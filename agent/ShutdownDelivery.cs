using System.Text.Json;

namespace Dochazka.Agent;

internal static class ShutdownDelivery
{
    public static readonly TimeSpan Budget = TimeSpan.FromSeconds(5);
    private static readonly TimeSpan RequestBudget = TimeSpan.FromSeconds(2);
    private static readonly TimeSpan LogoffWindow = TimeSpan.FromMilliseconds(750);

    public static async Task Run(Action<CancellationToken> collect, Func<CancellationToken, Task> send, CancellationToken deadline)
    {
        deadline.ThrowIfCancellationRequested();
        var waitForLogoff = Task.Delay(LogoffWindow, deadline);
        collect(deadline);
        var canSend = await TrySend(send, deadline);

        await waitForLogoff;
        deadline.ThrowIfCancellationRequested();
        collect(deadline);
        if (canSend) await TrySend(send, deadline);
    }

    private static async Task<bool> TrySend(Func<CancellationToken, Task> send, CancellationToken deadline)
    {
        using var request = CancellationTokenSource.CreateLinkedTokenSource(deadline);
        request.CancelAfter(RequestBudget);
        try
        {
            await send(request.Token);
            return true;
        }
        catch (Exception error) when (error is HttpRequestException or IOException or OperationCanceledException
            or JsonException or InvalidDataException)
        {
            return false;
        }
    }
}
