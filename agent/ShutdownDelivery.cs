using System.Text.Json;

namespace Dochazka.Agent;

internal static class ShutdownDelivery
{
    public static readonly TimeSpan Budget = TimeSpan.FromSeconds(12);
    private static readonly TimeSpan RequestBudget = TimeSpan.FromSeconds(4);

    public static async Task Run(Action<CancellationToken> collect, Func<CancellationToken, Task<bool>> send, CancellationToken deadline)
    {
        while (!deadline.IsCancellationRequested)
        {
            collect(deadline);
            using var request = CancellationTokenSource.CreateLinkedTokenSource(deadline);
            request.CancelAfter(RequestBudget);
            try
            {
                if (!await send(request.Token)) return;
            }
            catch (Exception error) when (error is HttpRequestException or IOException or OperationCanceledException
                or JsonException or InvalidDataException)
            {
                // The transaction remains queued, including when only the HTTP reply was lost.
            }
            await Task.Delay(TimeSpan.FromMilliseconds(250), deadline);
        }
    }
}
