using System.Text.Json;
using System.Text.Json.Serialization;

namespace AegisCore.EveLogUploader;

/// <summary>
/// Persisted user configuration. Loaded from
/// %APPDATA%\AegisCore\EveLogUploader\config.json on startup.
/// First-run flow creates a stub config and warns the user to fill in
/// api_token + (optionally) watch_paths.
/// </summary>
public sealed class UploaderConfig
{
    [JsonPropertyName("api_base_url")]
    public string ApiBaseUrl { get; set; } = "https://winterco.killsineve.online";

    [JsonPropertyName("api_token")]
    public string ApiToken { get; set; } = "";

    [JsonPropertyName("client_id")]
    public string ClientId { get; set; } = "";

    [JsonPropertyName("watch_paths")]
    public List<string> WatchPaths { get; set; } = new();

    [JsonPropertyName("auto_discover_eve_logs")]
    public bool AutoDiscoverEveLogs { get; set; } = true;

    [JsonPropertyName("upload_interval_seconds")]
    public int UploadIntervalSeconds { get; set; } = 10;

    [JsonPropertyName("max_chunk_bytes")]
    public int MaxChunkBytes { get; set; } = 262_144;

    [JsonPropertyName("display_name")]
    public string? DisplayName { get; set; }
}

public sealed class ConfigLoader
{
    private readonly ILogger<ConfigLoader> _log;
    private readonly string _configPath;

    public ConfigLoader(ILogger<ConfigLoader> log)
    {
        _log = log;
        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
        var dir = Path.Combine(appData, "AegisCore", "EveLogUploader");
        Directory.CreateDirectory(dir);
        _configPath = Path.Combine(dir, "config.json");
    }

    public string ConfigPath => _configPath;

    public UploaderConfig Load()
    {
        if (!File.Exists(_configPath))
        {
            var stub = new UploaderConfig
            {
                ClientId = Guid.NewGuid().ToString("N"),
            };
            Save(stub);
            _log.LogWarning("Created stub config at {Path}; populate api_token before uploads can succeed.", _configPath);
            return stub;
        }
        try
        {
            using var fs = File.OpenRead(_configPath);
            var cfg = JsonSerializer.Deserialize<UploaderConfig>(fs)
                      ?? throw new InvalidOperationException("config.json was empty");
            if (string.IsNullOrWhiteSpace(cfg.ClientId))
            {
                cfg.ClientId = Guid.NewGuid().ToString("N");
                Save(cfg);
                _log.LogInformation("Generated new client_id and persisted to config.");
            }
            return cfg;
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "Failed to load config from {Path}; treating as missing.", _configPath);
            return new UploaderConfig { ClientId = Guid.NewGuid().ToString("N") };
        }
    }

    public void Save(UploaderConfig cfg)
    {
        var json = JsonSerializer.Serialize(cfg, new JsonSerializerOptions
        {
            WriteIndented = true,
            DefaultIgnoreCondition = JsonIgnoreCondition.Never,
        });
        File.WriteAllText(_configPath, json);
    }
}
