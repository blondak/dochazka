using System.ComponentModel;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;

namespace Dochazka.Agent;

internal static class ServiceSessionConfirmation
{
    private const uint DoNotPersist = 0x00002;
    private const uint ExcludeCertificates = 0x00008;
    private const uint AlwaysShowUi = 0x00080;
    private const uint GenericCredentials = 0x40000;
    private const uint ErrorCancelled = 1223;

    public static async Task<bool> PromptAndVerify(Uri server, string code)
    {
        using var http = new HttpClient(new HttpClientHandler
        {
            AllowAutoRedirect = false,
            UseCookies = false,
            DefaultProxyCredentials = CredentialCache.DefaultNetworkCredentials,
        }) { BaseAddress = new Uri(server.AbsoluteUri.TrimEnd('/') + "/"), Timeout = TimeSpan.FromSeconds(15) };
        http.DefaultRequestHeaders.Add("X-Dochazka", "web");
        using var response = await http.PostAsJsonAsync("api/v1/service-session-details", new { code });
        RequireSuccess(response.StatusCode);
        var session = await response.Content.ReadFromJsonAsync<SessionDetails>()
            ?? throw new InvalidDataException("Chybí údaje o relaci.");
        var info = new CredentialUiInfo
        {
            Size = (uint)Marshal.SizeOf<CredentialUiInfo>(),
            Caption = "Docházka – servisní relace",
            Message = $"Označit relaci počítače {session.Station} od {session.From.ToLocalTime():g} jako servisní?\n"
                + $"Zadejte účet správce centrály {server.Authority}.",
        };
        var login = new StringBuilder(514);
        var password = new char[257];
        try
        {
            var result = CredUIPromptForCredentials(ref info, "Docházka", 0, 0, login, (uint)login.Capacity,
                password, (uint)password.Length, out _, DoNotPersist | ExcludeCertificates | AlwaysShowUi | GenericCredentials);
            if (result == ErrorCancelled) return false;
            if (result != 0) throw new Win32Exception((int)result);
            await Verify(http, code, login.ToString(), password);
            return true;
        }
        finally { Array.Clear(password); }
    }

    internal static async Task Verify(HttpClient http, string code, string login, char[] password)
    {
        // Serialize directly from the credential buffer so the password does not become a managed string.
        var passwordLength = Array.IndexOf(password, '\0');
        using var payload = new MemoryStream(8192);
        try
        {
            using (var writer = new Utf8JsonWriter(payload))
            {
                writer.WriteStartObject();
                writer.WriteString("code", code);
                writer.WriteString("login", login);
                writer.WriteString("password", password.AsSpan(0, passwordLength < 0 ? password.Length : passwordLength));
                writer.WriteEndObject();
            }
            using var content = new ByteArrayContent(payload.GetBuffer(), 0, (int)payload.Length);
            content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
            using var response = await http.PostAsync("api/v1/service-session-verify", content);
            RequireSuccess(response.StatusCode);
            if (await response.Content.ReadFromJsonAsync<Verification>() is not { Ok: true })
                throw new InvalidDataException("Chybí potvrzení označení relace.");
        }
        finally { Array.Clear(payload.GetBuffer()); }
    }

    private static void RequireSuccess(HttpStatusCode status)
    {
        if (status == HttpStatusCode.OK) return;
        throw new InvalidOperationException(status switch
        {
            HttpStatusCode.Unauthorized => "Ověření se nezdařilo. Zkontrolujte účet a heslo do centrály.",
            HttpStatusCode.Forbidden => "Označení servisní relace vyžaduje účet správce centrály.",
            HttpStatusCode.NotFound or HttpStatusCode.Conflict => "Kód vypršel nebo relace už není dostupná. Zkuste akci z ikony znovu.",
            HttpStatusCode.TooManyRequests => "Příliš mnoho pokusů. Zkuste to později.",
            HttpStatusCode.ProxyAuthenticationRequired => "Síťová proxy vyžaduje ověření. Zkontrolujte připojení k centrále.",
            _ => "Centrála požadavek nepotvrdila. Ověřte stav relace ve výkazu.",
        });
    }

    private record SessionDetails(string Station, DateTimeOffset From);
    private record Verification(bool Ok);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct CredentialUiInfo
    {
        public uint Size;
        public nint Parent;
        public string Message;
        public string Caption;
        public nint Banner;
    }

    [DllImport("credui.dll", EntryPoint = "CredUIPromptForCredentialsW", CharSet = CharSet.Unicode)]
    private static extern uint CredUIPromptForCredentials(ref CredentialUiInfo info, string target, nint reserved, uint authError,
        StringBuilder userName, uint userNameLength, [In, Out] char[] password, uint passwordLength,
        [MarshalAs(UnmanagedType.Bool)] out bool save, uint flags);
}
