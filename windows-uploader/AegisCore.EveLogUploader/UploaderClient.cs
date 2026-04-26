using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace AegisCore.EveLogUploader;

/// <summary>
/// HTTP client wrapper for the AegisCore Laravel ingest endpoint.
/// Single responsibility: serialise + POST a chunk, classify the
/// response into ok / offset_mismatch / retryable / fatal.
/// </summary>
public sealed class UploaderClient
{
    private readonly HttpClient _http;
    private readonly ILogger<UploaderClient> _log;

    public UploaderClient(HttpClient http, ILogger<UploaderClient> log)
    {
        _http = http;
        _log = log;
        _http.Timeout = TimeSpan.FromSeconds(60);
    }

    public async Task<UploadResult> UploadChunkAsync(
        UploaderConfig cfg, ChunkPayload payload, CancellationToken ct)
    {
        var url = TrimTrailingSlash(cfg.ApiBaseUrl) + "/api/eve-log-ingest/chunk";
        using var req = new HttpRequestMessage(HttpMethod.Post, url);
        req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", cfg.ApiToken);
        req.Content = JsonContent.Create(payload, options: new JsonSerializerOptions
        {
            DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
            PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        });

        try
        {
            using var res = await _http.SendAsync(req, ct);
            var body = await res.Content.ReadAsStringAsync(ct);
            if (res.IsSuccessStatusCode)
            {
                using var doc = JsonDocument.Parse(body);
                var accepted = doc.RootElement.TryGetProperty("accepted_offset", out var ao)
                    ? ao.GetInt64() : payload.OffsetEnd;
                return UploadResult.Ok(accepted);
            }
            if ((int)res.StatusCode == 409)
            {
                using var doc = JsonDocument.Parse(body);
                var accepted = doc.RootElement.TryGetProperty("accepted_offset", out var ao)
                    ? ao.GetInt64() : 0L;
                _log.LogWarning("Server reports offset_mismatch; resuming at {Accepted}", accepted);
                return UploadResult.OffsetMismatch(accepted);
            }
            // 4xx other than 409: probably auth / payload bug — non-retryable.
            if ((int)res.StatusCode >= 400 && (int)res.StatusCode < 500 && (int)res.StatusCode != 429)
            {
                _log.LogError("Non-retryable {Status}: {Body}", (int)res.StatusCode, body);
                return UploadResult.Fatal((int)res.StatusCode, body);
            }
            // 5xx / 429 → retryable.
            _log.LogWarning("Retryable {Status}: {Body}", (int)res.StatusCode, body);
            return UploadResult.Retry((int)res.StatusCode, body);
        }
        catch (HttpRequestException ex)
        {
            _log.LogWarning(ex, "Network error during upload — will retry.");
            return UploadResult.Retry(-1, ex.Message);
        }
        catch (TaskCanceledException ex) when (!ct.IsCancellationRequested)
        {
            _log.LogWarning(ex, "Upload timed out — will retry.");
            return UploadResult.Retry(-1, ex.Message);
        }
    }

    private static string TrimTrailingSlash(string s) =>
        s.EndsWith('/') ? s[..^1] : s;
}

public sealed class ChunkPayload
{
    [JsonPropertyName("client_id")] public string ClientId { get; set; } = "";
    [JsonPropertyName("source_path_hash")] public string SourcePathHash { get; set; } = "";
    [JsonPropertyName("filename")] public string Filename { get; set; } = "";
    [JsonPropertyName("log_type")] public string LogType { get; set; } = "unknown";
    [JsonPropertyName("listener")] public string? Listener { get; set; }
    [JsonPropertyName("channel_name")] public string? ChannelName { get; set; }
    [JsonPropertyName("channel_id")] public string? ChannelId { get; set; }
    [JsonPropertyName("session_started_at")] public string? SessionStartedAt { get; set; }
    [JsonPropertyName("offset_start")] public long OffsetStart { get; set; }
    [JsonPropertyName("offset_end")] public long OffsetEnd { get; set; }
    [JsonPropertyName("chunk_sha256")] public string ChunkSha256 { get; set; } = "";
    [JsonPropertyName("content")] public string Content { get; set; } = "";
    [JsonPropertyName("local_modified_at")] public string? LocalModifiedAt { get; set; }
    [JsonPropertyName("folder_hint")] public string? FolderHint { get; set; }
}

public abstract record UploadResult
{
    public static UploadResult Ok(long acceptedOffset) => new OkResult(acceptedOffset);
    public static UploadResult OffsetMismatch(long acceptedOffset) => new OffsetMismatchResult(acceptedOffset);
    public static UploadResult Retry(int httpStatus, string message) => new RetryResult(httpStatus, message);
    public static UploadResult Fatal(int httpStatus, string message) => new FatalResult(httpStatus, message);

    public sealed record OkResult(long AcceptedOffset) : UploadResult;
    public sealed record OffsetMismatchResult(long AcceptedOffset) : UploadResult;
    public sealed record RetryResult(int HttpStatus, string Message) : UploadResult;
    public sealed record FatalResult(int HttpStatus, string Message) : UploadResult;
}
