using System.Globalization;
using System.Runtime.InteropServices;
using System.Security.Principal;
using Microsoft.Win32.SafeHandles;

namespace Dochazka.Agent;

internal static class WindowsSessions
{
    private const int ClientProtocolType = 16;
    private const int TokenStatisticsClass = 10;
    private const int TokenElevationTypeClass = 18;
    private const int TokenLinkedTokenClass = 19;
    private const int TokenElevationTypeFull = 2;

    public static Journal.DesktopAccount? ReadConsoleUser(int sessionId)
    {
        if (sessionId <= 0 || !WTSQuerySessionInformation(0, sessionId, ClientProtocolType, out var protocol, out var size))
            return null;
        try
        {
            if (size < sizeof(ushort) || Marshal.ReadInt16(protocol) != 0)
                return null;
        }
        finally { WTSFreeMemory(protocol); }

        if (!WTSQueryUserToken((uint)sessionId, out var token))
        {
            token?.Dispose();
            return null;
        }
        using var userToken = token;
        try
        {
            using var identity = new WindowsIdentity(userToken.DangerousGetHandle());
            var sid = identity.User?.Value;
            var logon = ReadLogonId(userToken);
            if (sid == null || !sid.StartsWith("S-1-5-21-", StringComparison.Ordinal) || logon == null)
                return null;

            string? linked = null;
            if (GetLinkedToken(userToken, TokenLinkedTokenClass, out var handle, IntPtr.Size, out _) && handle != 0)
            {
                using var companion = new SafeAccessTokenHandle(handle);
                linked = ReadLogonId(companion);
            }
            var elevated = GetTokenInt(userToken, TokenElevationTypeClass, out var elevation, sizeof(int), out _)
                && elevation == TokenElevationTypeFull;
            return new Journal.DesktopAccount(sid, logon, linked, elevated);
        }
        catch (Exception error) when (error is System.Security.SecurityException or ArgumentException)
        {
            return null;
        }
    }

    public static int[]? SessionIds()
    {
        if (!WTSEnumerateSessions(0, 0, 1, out var buffer, out var count))
            return null;
        try
        {
            var ids = new int[count];
            var size = Marshal.SizeOf<SessionInfo>();
            for (var index = 0; index < count; index++)
                ids[index] = Marshal.PtrToStructure<SessionInfo>(IntPtr.Add(buffer, index * size)).Id;
            return ids;
        }
        finally { WTSFreeMemory(buffer); }
    }

    public static string? ReadLogonId(SafeAccessTokenHandle token)
    {
        if (!GetTokenStatistics(token, TokenStatisticsClass, out var data, Marshal.SizeOf<TokenStatistics>(), out _))
            return null;
        var id = data.AuthenticationId;
        return id is { High: 0, Low: 0 } ? null : FormatLogon(id.High, id.Low);
    }

    internal static string FormatLogon(int high, uint low) => "0x" + (unchecked((ulong)(uint)high << 32) | low).ToString("x", CultureInfo.InvariantCulture);

    [StructLayout(LayoutKind.Sequential)]
    private struct Luid
    {
        public uint Low;
        public int High;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct TokenStatistics
    {
        public Luid TokenId, AuthenticationId;
        public long Expiration;
        public int Type, ImpersonationLevel;
        public uint DynamicCharged, DynamicAvailable, GroupCount, PrivilegeCount;
        public Luid ModifiedId;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct SessionInfo
    {
        public int Id;
        public nint Name;
        public int State;
    }

    [DllImport("wtsapi32.dll", EntryPoint = "WTSQuerySessionInformationW")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQuerySessionInformation(nint server, int sessionId, int informationClass, out nint buffer, out int size);

    [DllImport("wtsapi32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQueryUserToken(uint sessionId, out SafeAccessTokenHandle token);

    [DllImport("wtsapi32.dll", EntryPoint = "WTSEnumerateSessionsW")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSEnumerateSessions(nint server, int reserved, int version, out nint buffer, out int count);

    [DllImport("wtsapi32.dll")]
    private static extern void WTSFreeMemory(nint buffer);

    [DllImport("advapi32.dll", EntryPoint = "GetTokenInformation")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetTokenStatistics(SafeAccessTokenHandle token, int informationClass, out TokenStatistics value, int size, out int required);

    [DllImport("advapi32.dll", EntryPoint = "GetTokenInformation")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetTokenInt(SafeAccessTokenHandle token, int informationClass, out int value, int size, out int required);

    [DllImport("advapi32.dll", EntryPoint = "GetTokenInformation")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetLinkedToken(SafeAccessTokenHandle token, int informationClass, out nint value, int size, out int required);
}
