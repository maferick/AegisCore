using AegisCore.EveLogUploader;
using Microsoft.Extensions.Hosting.WindowsServices;

// AegisCore EVE Log Uploader v1
//
// Windows .NET 8 Worker Service. Watches configured + auto-discovered
// EVE log folders, append-uploads new chunks of `.txt` files to the
// AegisCore Laravel ingest endpoint, and never modifies local logs.
//
// Reliability requirements (Phase 3 spec):
//   - retry with exponential backoff
//   - on HTTP 409 offset_mismatch, resume from server-reported offset
//   - on hash mismatch, retry the same chunk
//   - errors logged locally; never blocks EVE; never uploads non-log files
//
// Config: %APPDATA%\AegisCore\EveLogUploader\config.json
// State:  %APPDATA%\AegisCore\EveLogUploader\state.json

var builder = Host.CreateApplicationBuilder(args);

// Run as a Windows Service when installed; falls back to console host
// when launched directly so developers can run from a terminal.
builder.Services.AddWindowsService(options =>
{
    options.ServiceName = "AegisCore EVE Log Uploader";
});

builder.Services.AddSingleton<ConfigLoader>();
builder.Services.AddSingleton<StateStore>();
builder.Services.AddSingleton<LogFolderDiscovery>();
builder.Services.AddHttpClient<UploaderClient>();
builder.Services.AddHostedService<Worker>();

var host = builder.Build();
host.Run();
