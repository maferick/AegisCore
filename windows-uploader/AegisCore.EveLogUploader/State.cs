using System.Text.Json;
using System.Text.Json.Serialization;

namespace AegisCore.EveLogUploader;

/// <summary>
/// Per-file uploaded-offset state. Persisted to state.json so a
/// service restart resumes where it left off without re-uploading
/// already-accepted bytes.
/// </summary>
public sealed class FileState
{
    [JsonPropertyName("source_path_hash")]
    public string SourcePathHash { get; set; } = "";

    [JsonPropertyName("file_path")]
    public string FilePath { get; set; } = "";

    [JsonPropertyName("filename")]
    public string Filename { get; set; } = "";

    [JsonPropertyName("file_size")]
    public long FileSize { get; set; }

    [JsonPropertyName("last_modified_at")]
    public DateTime LastModifiedAt { get; set; }

    [JsonPropertyName("uploaded_byte_offset")]
    public long UploadedByteOffset { get; set; }

    [JsonPropertyName("last_chunk_sha256")]
    public string LastChunkSha256 { get; set; } = "";

    [JsonPropertyName("last_upload_at")]
    public DateTime? LastUploadAt { get; set; }

    [JsonPropertyName("last_status")]
    public string? LastStatus { get; set; }

    [JsonPropertyName("last_error")]
    public string? LastError { get; set; }
}

public sealed class StateFile
{
    [JsonPropertyName("files")]
    public Dictionary<string, FileState> Files { get; set; } = new();
}

public sealed class StateStore
{
    private readonly ILogger<StateStore> _log;
    private readonly string _statePath;
    private readonly object _lock = new();
    private StateFile _state = new();

    public StateStore(ILogger<StateStore> log)
    {
        _log = log;
        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
        var dir = Path.Combine(appData, "AegisCore", "EveLogUploader");
        Directory.CreateDirectory(dir);
        _statePath = Path.Combine(dir, "state.json");
        Load();
    }

    private void Load()
    {
        if (!File.Exists(_statePath)) return;
        try
        {
            using var fs = File.OpenRead(_statePath);
            _state = JsonSerializer.Deserialize<StateFile>(fs) ?? new StateFile();
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "Failed to load state.json; starting empty.");
            _state = new StateFile();
        }
    }

    public FileState GetOrCreate(string sourcePathHash, string filePath)
    {
        lock (_lock)
        {
            if (!_state.Files.TryGetValue(sourcePathHash, out var s))
            {
                s = new FileState
                {
                    SourcePathHash = sourcePathHash,
                    FilePath = filePath,
                    Filename = Path.GetFileName(filePath),
                };
                _state.Files[sourcePathHash] = s;
            }
            return s;
        }
    }

    public void Update(FileState s)
    {
        lock (_lock)
        {
            _state.Files[s.SourcePathHash] = s;
            Persist();
        }
    }

    public void Persist()
    {
        lock (_lock)
        {
            var tmp = _statePath + ".tmp";
            var json = JsonSerializer.Serialize(_state, new JsonSerializerOptions { WriteIndented = true });
            File.WriteAllText(tmp, json);
            // Atomic-ish replace.
            File.Move(tmp, _statePath, overwrite: true);
        }
    }
}
