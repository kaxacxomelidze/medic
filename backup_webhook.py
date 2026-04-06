#!/usr/bin/env python3
from http.server import HTTPServer, BaseHTTPRequestHandler
import subprocess
import json
import os

class BackupHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path == "/backup":
            # Get content length
            content_length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(content_length).decode("utf-8") if content_length > 0 else ""
            
            # Parse mode
            mode = "local"
            try:
                data = json.loads(body) if body else {}
                if data.get("s3"):
                    mode = "both"
            except:
                pass
            
            # Run backup
            try:
                result = subprocess.run(
                    ["/root/sanmedic/do_backup.sh", mode],
                    capture_output=True,
                    text=True,
                    timeout=300
                )
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Access-Control-Allow-Origin", "*")
                self.end_headers()
                self.wfile.write(json.dumps({
                    "success": result.returncode == 0,
                    "output": result.stdout[-1000:] if result.stdout else "",
                    "error": result.stderr[-500:] if result.stderr else ""
                }).encode())
            except Exception as e:
                self.send_response(500)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps({"success": False, "error": str(e)}).encode())
        else:
            self.send_response(404)
            self.end_headers()
    
    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()

if __name__ == "__main__":
    server = HTTPServer(("0.0.0.0", 9999), BackupHandler)
    print("Backup webhook running on port 9999")
    server.serve_forever()
