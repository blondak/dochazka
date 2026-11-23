using System.ComponentModel;
using System.Runtime.InteropServices;

namespace Dochazka.Agent;

// ServiceBase does not expose SERVICE_ACCEPT_PRESHUTDOWN. Register the service
// with SCM directly so it can finish delivery before ordinary services stop.
internal sealed class WindowsServiceHost(AttendanceService service)
{
    internal const string Name = "DochazkaAgent";
    private const uint Stop = 1, Interrogate = 4, Shutdown = 5, SessionChange = 14, Preshutdown = 15;
    private const uint AcceptStop = 1, AcceptShutdown = 4, AcceptSessionChange = 128, AcceptPreshutdown = 256;
    private readonly TaskCompletionSource<uint> stopping = new(TaskCreationOptions.RunContinuationsAsynchronously);
    private nint handle;

    internal void Run()
    {
        ServiceMain main = RunService;
        var table = new ServiceEntry[] { new() { Name = Name, Main = main }, default };
        if (!StartServiceCtrlDispatcher(table)) throw new Win32Exception();
        GC.KeepAlive(main);
    }

    private void RunService(uint argc, nint argv)
    {
        Handler handler = Control;
        handle = RegisterServiceCtrlHandlerEx(Name, handler, 0);
        if (handle == 0) return;
        uint errorCode = 0;
        try
        {
            Report(2, wait: 15000); // START_PENDING
            var worker = service.Start();
            Report(4, AcceptStop | AcceptShutdown | AcceptSessionChange | AcceptPreshutdown); // RUNNING
            Task.WhenAny(worker, stopping.Task).GetAwaiter().GetResult();
            bool shuttingDown = stopping.Task.IsCompletedSuccessfully && stopping.Task.Result is Preshutdown or Shutdown;
            Report(3, wait: 15000); // STOP_PENDING
            service.StopAndWait(shuttingDown);
            if (worker.IsFaulted) worker.GetAwaiter().GetResult();
        }
        catch (Exception error)
        {
            errorCode = 1064;
            LogError(error);
        }
        finally
        {
            Report(1, error: errorCode); // STOPPED
            GC.KeepAlive(handler);
        }
    }

    private uint Control(uint control, uint eventType, nint data, nint context)
    {
        try
        {
            switch (control)
            {
                case Stop:
                case Shutdown:
                case Preshutdown:
                    stopping.TrySetResult(control);
                    break;
                case SessionChange when data != 0 && eventType is 5 or 6: // WTS_SESSION_LOGON / LOGOFF
                    service.SessionChanged(Marshal.ReadInt32(data, sizeof(uint)), eventType == 5);
                    break;
                case SessionChange:
                case Interrogate:
                    break;
                default:
                    return 120; // ERROR_CALL_NOT_IMPLEMENTED
            }
            return 0;
        }
        catch (Exception error) { LogError(error); return 1064; }
    }

    private static void LogError(Exception error)
    {
        try { System.Diagnostics.EventLog.WriteEntry(Name, error.GetBaseException().Message, System.Diagnostics.EventLogEntryType.Error); }
        catch (Exception) { /* The event log can already be unavailable during shutdown. */ }
    }

    private void Report(uint state, uint accepts = 0, uint wait = 0, uint error = 0)
    {
        var status = new ServiceStatus { Type = 0x10, State = state, Accepts = accepts, Error = error,
            WaitHint = wait, Checkpoint = wait == 0 ? 0u : 1u };
        if (!SetServiceStatus(handle, ref status) && state != 1) throw new Win32Exception();
    }

    private delegate void ServiceMain(uint argc, nint argv);
    private delegate uint Handler(uint control, uint eventType, nint data, nint context);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct ServiceEntry { public string? Name; public ServiceMain? Main; }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceStatus { public uint Type, State, Accepts, Error, ServiceError, Checkpoint, WaitHint; }

    [DllImport("advapi32.dll", EntryPoint = "StartServiceCtrlDispatcherW", SetLastError = true)]
    private static extern bool StartServiceCtrlDispatcher([In] ServiceEntry[] table);
    [DllImport("advapi32.dll", EntryPoint = "RegisterServiceCtrlHandlerExW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern nint RegisterServiceCtrlHandlerEx(string name, Handler handler, nint context);
    [DllImport("advapi32.dll", SetLastError = true)]
    private static extern bool SetServiceStatus(nint handle, ref ServiceStatus status);
}
