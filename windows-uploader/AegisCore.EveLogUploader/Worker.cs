using System.Security.Cryptography;
using System.Text;

namespace AegisCore.EveLogUploader;

/// <summary>
/// Main loop. On each tick:
///   1. Discover/refresh the watch_paths set.
///   2. For each .txt file, compare current size against state.json's
///      uploaded_byte_offset.
///   3. Read new bytes (capped by max_chunk_bytes), compute sha256,
///      submit. On ok update state. On offset_mismatch align to
///      server's accepted_offset and retry next tick. On retryable
///      back off exponentially. On fatal mark the file with last_error
///      and skip until next config reload.
///
/// Never blocks EVE — opens files with FileShare.ReadWrite so the
/// game keeps writing while we read. Never modifies or deletes.
/// </summary>
public sealed class Worker : BackgroundService
{
    private readonly ILogger<Worker> _log;
    private readonly ConfigLoader _configLoader;
    private readonly StateStore _state;
    private readonly LogFolderDiscovery _discovery;
    private readonly UploaderClient _uploader;

    private UploaderConfig _config = new();
    private DateTime _configLoadedAt = DateTime.MinValue;

    private readonly Dictionary<string, int> _retryCounters = new();

    public Worker(
        ILogger<Worker> log,
        ConfigLoader configLoader,
        StateStore state,
        LogFolderDiscovery discovery,
        UploaderClient uploader)
    {
        _log = log;
        _configLoader = configLoader;
        _state = state;
        _discovery = discovery;
        _uploader = uploader;
    }

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        _log.LogInformation("AegisCore EVE Log Uploader starting…");
        ReloadConfig();

        while (!ct.IsCancellationRequested)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(_config.ApiToken))
                {
                    _log.LogWarning("api_token missing — set it in {Path} and the service will pick it up.", _configLoader.ConfigPath);
                }
                else
                {
                    await TickAsync(ct);
                }
            }
            catch (OperationCanceledException) { /* shutdown */ }
            catch (Exception ex)
            {
                _log.LogError(ex, "Unhandled error in main loop tick.");
            }

            // Reload config every 60s so the user can edit watch_paths
            // / api_token without restarting the service.
            if (DateTime.UtcNow - _configLoadedAt > TimeSpan.FromSeconds(60))
            {
                ReloadConfig();
            }

            try
            {
                await Task.Delay(TimeSpan.FromSeconds(Math.Max(1, _config.UploadIntervalSeconds)), ct);
            }
            catch (TaskCanceledException) { /* shutdown */ }
        }

        _log.LogInformation("AegisCore EVE Log Uploader stopping.");
    }

    private void ReloadConfig()
    {
        _config = _configLoader.Load();
        _configLoadedAt = DateTime.UtcNow;
    }

    private async Task TickAsync(CancellationToken ct)
    {
        var roots = new List<string>();
        if (_config.AutoDiscoverEveLogs)
        {
            roots.AddRange(_discovery.Discover());
        }
        roots.AddRange(_config.WatchPaths.Where(Directory.Exists));
        roots = roots.Distinct(StringComparer.OrdinalIgnoreCase).ToList();

        if (roots.Count == 0)
        {
            _log.LogDebug("No watch roots discovered. Idle.");
            return;
        }

        foreach (var root in roots)
        {
            ct.ThrowIfCancellationRequested();
            string[] files;
            try
            {
                files = Directory.GetFiles(root, "*.txt", SearchOption.TopDirectoryOnly);
            }
            catch (Exception ex)
            {
                _log.LogWarning(ex, "Failed to enumerate {Root}", root);
                continue;
            }

            foreach (var path in files)
            {
                ct.ThrowIfCancellationRequested();
                try
                {
                    await ProcessFileAsync(path, root, ct);
                }
                catch (Exception ex)
                {
                    _log.LogWarning(ex, "Skipping {Path}: {Reason}", path, ex.Message);
                }
            }
        }
    }

    private async Task ProcessFileAsync(string path, string folderHint, CancellationToken ct)
    {
        FileInfo info;
        try { info = new FileInfo(path); }
        catch (Exception ex) { _log.LogDebug(ex, "Skip {Path}: stat failed", path); return; }
        if (!info.Exists || info.Length == 0) return;

        var hash = HashPath(path);
        var state = _state.GetOrCreate(hash, path);
        state.Filename = info.Name;
        state.FileSize = info.Length;
        state.LastModifiedAt = info.LastWriteTimeUtc;

        if (state.UploadedByteOffset >= info.Length) return;

        var sliceStart = state.UploadedByteOffset;
        var maxBytes = Math.Max(4096, _config.MaxChunkBytes);
        var sliceEnd = Math.Min(info.Length, sliceStart + maxBytes);
        var sliceLen = (int)(sliceEnd - sliceStart);
        if (sliceLen <= 0) return;

        byte[] rawBytes;
        try
        {
            // FileShare.ReadWrite + Delete so EVE keeps writing freely.
            using var fs = new FileStream(path, FileMode.Open, FileAccess.Read,
                FileShare.ReadWrite | FileShare.Delete);
            fs.Seek(sliceStart, SeekOrigin.Begin);
            rawBytes = new byte[sliceLen];
            var read = 0;
            while (read < sliceLen)
            {
                var got = await fs.ReadAsync(rawBytes.AsMemory(read, sliceLen - read), ct);
                if (got == 0) break;
                read += got;
            }
            if (read < sliceLen)
            {
                Array.Resize(ref rawBytes, read);
                sliceEnd = sliceStart + read;
            }
        }
        catch (IOException ex)
        {
            // OneDrive sync lock or partial-write contention — just retry next tick.
            _log.LogDebug(ex, "Read contention on {Path}, will retry", path);
            return;
        }

        // Wire-safe transport: ship the RAW bytes from disk as base64.
        // Avoids the UTF-8 ↔ UTF-16 ↔ JSON round-trip that was losing
        // 2 bytes per chunk (every JSON-string normalisation step has
        // a chance to mutate control chars / surrogates / BOM markers).
        // Server decodes base64 → identical bytes → identical sha256.
        // Server's parser handles BOM + line normalisation downstream.
        sliceEnd = sliceStart + rawBytes.Length;
        var sha256 = ToHex(SHA256.HashData(rawBytes));
        var contentB64 = Convert.ToBase64String(rawBytes);

        var payload = new ChunkPayload
        {
            ClientId = _config.ClientId,
            SourcePathHash = hash,
            Filename = info.Name,
            LogType = "unknown",
            FolderHint = folderHint,
            OffsetStart = sliceStart,
            OffsetEnd = sliceEnd,
            ChunkSha256 = sha256,
            ContentB64 = contentB64,
            LocalModifiedAt = info.LastWriteTimeUtc.ToString("o"),
        };

        // Apply per-file exponential backoff on consecutive retry results.
        if (_retryCounters.TryGetValue(hash, out var rc) && rc > 0)
        {
            var delaySec = Math.Min(60, (int)Math.Pow(2, Math.Min(rc, 6)));
            _log.LogDebug("Backing off {Sec}s on {Path}", delaySec, path);
            try { await Task.Delay(TimeSpan.FromSeconds(delaySec), ct); }
            catch (TaskCanceledException) { return; }
        }

        var result = await _uploader.UploadChunkAsync(_config, payload, ct);
        switch (result)
        {
            case UploadResult.OkResult ok:
                state.UploadedByteOffset = ok.AcceptedOffset;
                state.LastChunkSha256 = sha256;
                state.LastUploadAt = DateTime.UtcNow;
                state.LastStatus = "ok";
                state.LastError = null;
                _state.Update(state);
                _retryCounters.Remove(hash);
                break;

            case UploadResult.OffsetMismatchResult mismatch:
                _log.LogWarning("Resyncing {Path}: server accepted_offset={Off}", path, mismatch.AcceptedOffset);
                state.UploadedByteOffset = mismatch.AcceptedOffset;
                state.LastStatus = "offset_mismatch";
                _state.Update(state);
                // Don't increment retry counter — this is a resync, not a failure.
                break;

            case UploadResult.RetryResult retry:
                _retryCounters[hash] = (_retryCounters.TryGetValue(hash, out var c) ? c : 0) + 1;
                state.LastStatus = $"retry_{retry.HttpStatus}";
                state.LastError = retry.Message;
                _state.Update(state);
                break;

            case UploadResult.FatalResult fatal:
                _retryCounters[hash] = (_retryCounters.TryGetValue(hash, out var c2) ? c2 : 0) + 1;
                state.LastStatus = $"fatal_{fatal.HttpStatus}";
                state.LastError = fatal.Message;
                _state.Update(state);
                break;
        }
    }

    private static string HashPath(string path)
    {
        var bytes = Encoding.UTF8.GetBytes(path.ToLowerInvariant());
        return ToHex(SHA256.HashData(bytes));
    }

    private static string ToHex(byte[] data)
    {
        var sb = new StringBuilder(data.Length * 2);
        foreach (var b in data) sb.Append(b.ToString("x2"));
        return sb.ToString();
    }

    private static string StripUtf8Bom(string s)
    {
        return s.Length > 0 && s[0] == '﻿' ? s[1..] : s;
    }
}
