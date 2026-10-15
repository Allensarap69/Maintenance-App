import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:webview_flutter/webview_flutter.dart';

// The app locates the XAMPP server on the local Wi-Fi automatically: it asks
// each host on the phone's subnet for ping.php and uses whichever answers with
// the marker below, so the PC's IP can change freely. The last working host is
// cached and re-validated on each launch.
// To skip discovery (e.g. a public production deploy), set kFixedBaseUrl.
const String kFixedBaseUrl = '';
const String kAppPath = '/Advance_Database-System/';
const String _kPingPath = '${kAppPath}ping.php';
const String _kPingMarker = 'EVERSURE_PONG';
const String _kEmulatorHost = '10.0.2.2'; // host machine as seen by an emulator
const String _kPrefsHostKey = 'server_host';
const Duration _kProbeTimeout = Duration(milliseconds: 900);

void main() {
  runApp(const EversureApp());
}

class EversureApp extends StatelessWidget {
  const EversureApp({super.key});

  @override
  Widget build(BuildContext context) {
    return const MaterialApp(
      title: 'Eversure',
      debugShowCheckedModeBanner: false,
      home: WebViewScreen(),
    );
  }
}

class WebViewScreen extends StatefulWidget {
  const WebViewScreen({super.key});

  @override
  State<WebViewScreen> createState() => _WebViewScreenState();
}

enum _Phase { searching, web, failed }

class _WebViewScreenState extends State<WebViewScreen> {
  late final WebViewController _controller;
  _Phase _phase = _Phase.searching;
  bool _pageLoading = true;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) => setState(() => _pageLoading = true),
          onPageFinished: (_) => setState(() => _pageLoading = false),
          onWebResourceError: (error) {
            if (error.isForMainFrame != true) return;
            setState(() => _phase = _Phase.failed);
          },
        ),
      );
    _start();
  }

  Future<void> _start() async {
    setState(() => _phase = _Phase.searching);
    final base = await _resolveBaseUrl();
    if (!mounted) return;
    if (base == null) {
      setState(() => _phase = _Phase.failed);
    } else {
      setState(() => _phase = _Phase.web);
      _controller.loadRequest(Uri.parse(base));
    }
  }

  Future<String?> _resolveBaseUrl() async {
    if (kFixedBaseUrl.isNotEmpty) return kFixedBaseUrl;

    final prefs = await SharedPreferences.getInstance();
    final cached = prefs.getString(_kPrefsHostKey);
    if (cached != null && await _isServer(cached)) {
      return _baseFor(cached);
    }

    final found =
        await _findOnSubnet() ??
        (await _isServer(_kEmulatorHost) ? _kEmulatorHost : null);
    if (found == null) return null;
    await prefs.setString(_kPrefsHostKey, found);
    return _baseFor(found);
  }

  String _baseFor(String host) => 'http://$host$kAppPath';

  Future<String?> _deviceIpv4() async {
    try {
      final ifaces = await NetworkInterface.list(
        type: InternetAddressType.IPv4,
      );
      for (final iface in ifaces) {
        for (final addr in iface.addresses) {
          if (!addr.isLoopback) return addr.address;
        }
      }
    } catch (_) {}
    return null;
  }

  Future<String?> _findOnSubnet() async {
    final ip = await _deviceIpv4();
    if (ip == null) return null;
    final prefix = ip.substring(0, ip.lastIndexOf('.'));
    const batchSize = 64;
    for (var start = 1; start <= 254; start += batchSize) {
      final end = start + batchSize - 1;
      final hosts = [
        for (var i = start; i <= end && i <= 254; i++) '$prefix.$i',
      ];
      final results = await Future.wait(
        hosts.map((h) async => (await _isServer(h)) ? h : null),
      );
      for (final h in results) {
        if (h != null) return h;
      }
    }
    return null;
  }

  Future<bool> _isServer(String host) async {
    final client = HttpClient()..connectionTimeout = _kProbeTimeout;
    try {
      final req = await client
          .get(host, 80, _kPingPath)
          .timeout(_kProbeTimeout);
      final res = await req.close().timeout(_kProbeTimeout);
      if (res.statusCode != HttpStatus.ok) return false;
      final body = await res
          .transform(utf8.decoder)
          .join()
          .timeout(_kProbeTimeout);
      return body.contains(_kPingMarker);
    } catch (_) {
      return false;
    } finally {
      client.close(force: true);
    }
  }

  Future<bool> _handleBack() async {
    if (await _controller.canGoBack()) {
      await _controller.goBack();
      return false;
    }
    return true;
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await _handleBack() && context.mounted) {
          Navigator.of(context).maybePop();
        }
      },
      child: Scaffold(
        body: SafeArea(
          child: switch (_phase) {
            _Phase.searching => const _SearchingView(),
            _Phase.failed => _ErrorView(onRetry: _start),
            _Phase.web => Stack(
              children: [
                WebViewWidget(controller: _controller),
                if (_pageLoading) const LinearProgressIndicator(minHeight: 3),
              ],
            ),
          },
        ),
      ),
    );
  }
}

class _SearchingView extends StatelessWidget {
  const _SearchingView();

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          CircularProgressIndicator(),
          SizedBox(height: 16),
          Text(
            'Looking for the server on this Wi-Fi…',
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

class _ErrorView extends StatelessWidget {
  const _ErrorView({required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.wifi_off, size: 48),
            const SizedBox(height: 12),
            const Text(
              'Cannot find the server.\nMake sure this device is on the same '
              'Wi-Fi as the computer running XAMPP, then try again.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      ),
    );
  }
}
