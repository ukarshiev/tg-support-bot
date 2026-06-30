param(
    [string] $ListenAddress = '192.168.0.100',
    [int] $ListenPort = 55614,
    [string] $TargetAddress = '127.0.0.1',
    [int] $TargetPort = 55612
)

$ErrorActionPreference = 'Stop'

Add-Type -TypeDefinition @"
using System;
using System.Net;
using System.Net.Sockets;
using System.Threading.Tasks;

public static class RelaxaTcpProxy
{
    public static void Run(string listenAddress, int listenPort, string targetAddress, int targetPort)
    {
        var listener = new TcpListener(IPAddress.Parse(listenAddress), listenPort);
        listener.Start();
        Console.WriteLine(
            string.Format(
                "TCP proxy listening on {0}:{1} -> {2}:{3}",
                listenAddress,
                listenPort,
                targetAddress,
                targetPort
            )
        );

        while (true)
        {
            var source = listener.AcceptTcpClient();
            Task.Run(async () => await HandleClient(source, targetAddress, targetPort));
        }
    }

    private static async Task HandleClient(TcpClient source, string targetAddress, int targetPort)
    {
        using (source)
        using (var target = new TcpClient())
        {
            try
            {
                await target.ConnectAsync(targetAddress, targetPort);

                using (var sourceStream = source.GetStream())
                using (var targetStream = target.GetStream())
                {
                    var toTarget = sourceStream.CopyToAsync(targetStream);
                    var toSource = targetStream.CopyToAsync(sourceStream);
                    await Task.WhenAny(toTarget, toSource);
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine(ex.Message);
            }
        }
    }
}
"@

[RelaxaTcpProxy]::Run($ListenAddress, $ListenPort, $TargetAddress, $TargetPort)
