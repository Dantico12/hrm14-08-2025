const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 5000;

// Start PHP built-in server
const phpServer = spawn('php', ['-S', `0.0.0.0:${PORT + 1}`, '-t', '.']);

phpServer.stdout.on('data', (data) => {
  console.log(`PHP Server: ${data}`);
});

phpServer.stderr.on('data', (data) => {
  console.error(`PHP Server Error: ${data}`);
});

// Create a simple proxy server
const server = http.createServer((req, res) => {
  // Set CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  // Handle preflight requests
  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  // Proxy requests to PHP server
  const options = {
    hostname: '127.0.0.1',
    port: PORT + 1,
    path: req.url,
    method: req.method,
    headers: req.headers
  };

  const proxyReq = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode, proxyRes.headers);
    proxyRes.pipe(res);
  });

  proxyReq.on('error', (err) => {
    console.error('Proxy error:', err);
    res.writeHead(500);
    res.end('Internal Server Error');
  });

  req.pipe(proxyReq);
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Server running on http://0.0.0.0:${PORT}`);
  console.log(`PHP server running on port ${PORT + 1}`);
});

process.on('SIGINT', () => {
  phpServer.kill();
  process.exit();
});