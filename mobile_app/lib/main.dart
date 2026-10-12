import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

// The app tries each URL in order until one loads.
// 192.168.1.16 is this PC's LAN address — for real phones on the same Wi-Fi
// (requires inbound TCP:80 allowed through Windows Firewall).
// 10.0.2.2 reaches the host machine from inside an Android emulator.
// Replace with the production URL (e.g. https://your-app.onrender.com) once deployed.
const List<String> kBaseUrls = [
  'http://192.168.1.16/Advance_Database-System/',
  'http://10.0.2.2/Advance_Database-System/',
];

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

class _WebViewScreenState extends State<WebViewScreen> {
  late final WebViewController _controller;
  int _urlIndex = 0;
  bool _loading = true;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) => setState(() => _loading = true),
          onPageFinished: (_) => setState(() => _loading = false),
          onWebResourceError: (error) {
            if (error.isForMainFrame != true) return;
            _onLoadFailed();
          },
        ),
      );
    _loadCurrent();
  }

  void _loadCurrent() {
    _failed = false;
    _controller.loadRequest(Uri.parse(kBaseUrls[_urlIndex]));
  }

  void _onLoadFailed() {
    if (_urlIndex + 1 < kBaseUrls.length) {
      setState(() => _urlIndex++);
      _loadCurrent();
    } else {
      setState(() {
        _urlIndex = 0;
        _loading = false;
        _failed = true;
      });
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
          child: _failed
              ? _ErrorView(
                  onRetry: () {
                    setState(() => _loading = true);
                    _loadCurrent();
                  },
                )
              : Stack(
                  children: [
                    WebViewWidget(controller: _controller),
                    if (_loading) const LinearProgressIndicator(minHeight: 3),
                  ],
                ),
        ),
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
              'Cannot reach the server.\nMake sure this device is on the same '
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
