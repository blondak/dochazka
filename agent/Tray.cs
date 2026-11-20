using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;

namespace Dochazka.Agent;

internal sealed class Tray(Uri server)
{
    private const uint CallbackMessage = 0x8001;
    private const uint WindowDestroyed = 2;
    private const uint ContextMenuMessage = 0x007b;
    private const uint LeftButtonUp = 0x0202;
    private const uint RightButtonUp = 0x0205;
    private const uint MouseSelect = 0x0400;
    private const uint KeyboardSelect = 0x0401;
    private const uint MenuService = 1;
    private const uint MenuPasskey = 2;
    private const uint MenuExit = 3;
    private static readonly uint TaskbarCreated = RegisterWindowMessage("TaskbarCreated");
    private nint window, defaultProcedure;
    private int opening;

    public static void Run(string address)
    {
        if (!Uri.TryCreate(address, UriKind.Absolute, out var server) || server.Scheme != Uri.UriSchemeHttps
            || server.UserInfo.Length != 0 || server.Query.Length != 0 || server.Fragment.Length != 0) return;
        using var instance = new Mutex(true, @"Local\DochazkaAgent.Tray", out var first);
        if (first) new Tray(server).Show();
    }

    private void Show()
    {
        window = CreateWindowEx(0, "STATIC", "Docházka", 0, 0, 0, 0, 0, 0, 0, 0, 0);
        if (window == 0) throw new Win32Exception();
        WindowProcedure procedure = HandleMessage;
        defaultProcedure = SetWindowLongPtr(window, -4, Marshal.GetFunctionPointerForDelegate(procedure));
        if (defaultProcedure == 0) throw new Win32Exception();
        try
        {
            SetIcon(0);
            while (GetMessage(out var message, 0, 0, 0) > 0)
                DispatchMessage(ref message);
        }
        finally
        {
            SetIcon(2);
            DestroyWindow(window);
            GC.KeepAlive(procedure);
        }
    }

    private void SetIcon(uint operation)
    {
        var item = new NotifyIconData
        {
            Size = (uint)Marshal.SizeOf<NotifyIconData>(), Window = window, Id = 1,
            Flags = 7, Callback = CallbackMessage, Icon = LoadIcon(0, 32516), Tooltip = "Docházka",
            Info = "", Title = "",
        };
        if (ShellNotifyIcon(operation, ref item) && operation == 0)
        {
            item.Version = 3; // Request keyboard selection and context menu notifications.
            ShellNotifyIcon(4, ref item);
        }
    }

    private nint HandleMessage(nint handle, uint message, nuint wParam, nint lParam)
    {
        if (message == CallbackMessage && (uint)lParam is LeftButtonUp or RightButtonUp or ContextMenuMessage or MouseSelect or KeyboardSelect) ShowMenu();
        else if (message == TaskbarCreated) SetIcon(0);
        else if (message == WindowDestroyed) PostQuitMessage(0);
        return CallWindowProc(defaultProcedure, handle, message, wParam, lParam);
    }

    private void ShowMenu()
    {
        var menu = CreatePopupMenu();
        if (menu == 0) return;
        try
        {
            AppendMenu(menu, 0, MenuService, "Označit relaci jako servisní");
            AppendMenu(menu, 0, MenuPasskey, "Označit pomocí passkey v prohlížeči");
            AppendMenu(menu, 0, MenuExit, "Ukončit ikonu");
            if (!GetCursorPos(out var point)) return;
            SetForegroundWindow(window);
            var selected = TrackPopupMenu(menu, 0x0102, point.X, point.Y, 0, window, 0);
            PostMessage(window, 0, 0, 0);
            if (selected is MenuService or MenuPasskey) _ = OpenRequest(selected == MenuPasskey);
            if (selected == MenuExit) PostQuitMessage(0);
        }
        finally { DestroyMenu(menu); }
    }

    private async Task OpenRequest(bool passkey)
    {
        if (Interlocked.Exchange(ref opening, 1) != 0) return;
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(18));
            var code = await ServiceSessionPipe.RequestCode(timeout.Token);
            if (passkey)
            {
                var url = server.AbsoluteUri.TrimEnd('/') + "/#service-session=" + code;
                Process.Start(new ProcessStartInfo(url) { UseShellExecute = true })?.Dispose();
                return;
            }
            if (await ServiceSessionConfirmation.PromptAndVerify(server, code))
                MessageBox(0, "Relace byla označena jako servisní.", "Docházka", 0x40);
        }
        catch (InvalidOperationException error) { MessageBox(0, error.Message, "Docházka", 0x30); }
        catch (Exception error) when (error is IOException or TimeoutException or OperationCanceledException
            or UnauthorizedAccessException or Win32Exception or HttpRequestException or System.Text.Json.JsonException)
        {
            MessageBox(0, passkey ? "Služba Docházka nebo prohlížeč nejsou dostupné. Zkuste to znovu."
                : "Označení se nepodařilo potvrdit. Ověřte spojení s centrálou a stav relace ve výkazu.", "Docházka", 0x30);
        }
        finally { Volatile.Write(ref opening, 0); }
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct Point { public int X, Y; }

    [StructLayout(LayoutKind.Sequential)]
    private struct Message { public nint Window; public uint Id; public nuint W; public nint L; public uint Time; public Point Position; public uint Private; }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct NotifyIconData
    {
        public uint Size;
        public nint Window;
        public uint Id, Flags, Callback;
        public nint Icon;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 128)] public string Tooltip;
        public uint State, StateMask;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)] public string Info;
        public uint Version;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 64)] public string Title;
        public uint InfoFlags;
        public Guid Guid;
        public nint BalloonIcon;
    }

    private delegate nint WindowProcedure(nint window, uint message, nuint wParam, nint lParam);

    [DllImport("user32.dll", EntryPoint = "CreateWindowExW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern nint CreateWindowEx(uint exStyle, string windowClass, string title, uint style,
        int x, int y, int width, int height, nint parent, nint menu, nint instance, nint parameter);
    [DllImport("user32.dll", EntryPoint = "SetWindowLongPtrW", SetLastError = true)]
    private static extern nint SetWindowLongPtr(nint window, int index, nint value);
    [DllImport("user32.dll", EntryPoint = "CallWindowProcW")]
    private static extern nint CallWindowProc(nint procedure, nint window, uint message, nuint wParam, nint lParam);
    [DllImport("user32.dll", EntryPoint = "GetMessageW")]
    private static extern int GetMessage(out Message message, nint window, uint first, uint last);
    [DllImport("user32.dll", EntryPoint = "DispatchMessageW")]
    private static extern nint DispatchMessage(ref Message message);
    [DllImport("user32.dll", EntryPoint = "RegisterWindowMessageW", CharSet = CharSet.Unicode)]
    private static extern uint RegisterWindowMessage(string name);
    [DllImport("user32.dll", EntryPoint = "PostMessageW")]
    private static extern bool PostMessage(nint window, uint message, nuint wParam, nint lParam);
    [DllImport("user32.dll", EntryPoint = "LoadIconW")]
    private static extern nint LoadIcon(nint instance, nint icon);
    [DllImport("user32.dll")] private static extern void PostQuitMessage(int exitCode);
    [DllImport("user32.dll")] private static extern bool DestroyWindow(nint window);
    [DllImport("user32.dll")] private static extern nint CreatePopupMenu();
    [DllImport("user32.dll", EntryPoint = "AppendMenuW", CharSet = CharSet.Unicode)]
    private static extern bool AppendMenu(nint menu, uint flags, nuint item, string text);
    [DllImport("user32.dll")] private static extern uint TrackPopupMenu(nint menu, uint flags, int x, int y, int reserved, nint window, nint rect);
    [DllImport("user32.dll")] private static extern bool DestroyMenu(nint menu);
    [DllImport("user32.dll")] private static extern bool SetForegroundWindow(nint window);
    [DllImport("user32.dll")] private static extern bool GetCursorPos(out Point point);
    [DllImport("user32.dll", EntryPoint = "MessageBoxW", CharSet = CharSet.Unicode)]
    private static extern int MessageBox(nint window, string message, string title, uint flags);
    [DllImport("shell32.dll", EntryPoint = "Shell_NotifyIconW")]
    private static extern bool ShellNotifyIcon(uint message, ref NotifyIconData data);
}
