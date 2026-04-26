namespace AegisCore.EveLogUploader;

/// <summary>
/// Auto-discover candidate EVE log folders on first run. The user's
/// path layout is unpredictable on Windows — Documents may be
/// redirected to OneDrive, may live under localized "Documenten",
/// and the user may have their own custom location.
///
/// We probe the well-known set the spec lists, filter to actually-
/// existing directories, and never scan outside.
/// </summary>
public sealed class LogFolderDiscovery
{
    private readonly ILogger<LogFolderDiscovery> _log;

    private static readonly string[] RelativeCandidates =
    {
        @"Documents\EVE\logs\Gamelogs",
        @"Documents\EVE\logs\Chatlogs",
        @"OneDrive\Documents\EVE\logs\Gamelogs",
        @"OneDrive\Documents\EVE\logs\Chatlogs",
        @"OneDrive\Downloads\Documenten\EVE\logs\Gamelogs",
        @"OneDrive\Downloads\Documenten\EVE\logs\Chatlogs",
        @"Downloads\Documenten\EVE\logs\Gamelogs",
        @"Downloads\Documenten\EVE\logs\Chatlogs",
    };

    public LogFolderDiscovery(ILogger<LogFolderDiscovery> log)
    {
        _log = log;
    }

    public IReadOnlyList<string> Discover()
    {
        var userProfile = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        var found = new List<string>();
        foreach (var rel in RelativeCandidates)
        {
            var abs = Path.Combine(userProfile, rel);
            if (Directory.Exists(abs))
            {
                found.Add(abs);
                _log.LogInformation("Discovered EVE log folder: {Path}", abs);
            }
        }
        return found;
    }
}
