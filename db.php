<?php
/**
 * db.php
 * Database Connection Helper
 * Uses PDO for secure database access. Implements Singleton pattern.
 */

class Database {
    private static $instance = null;
    private $conn;

    private $host = '127.0.0.1';
    private $db_name = 'support_dashboard';
    private $username = 'root';
    private $password = ''; // Default XAMPP password is empty

    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            // Elegant error presentation depending on request content-type
            $is_api_request = (
                isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false ||
                isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false ||
                basename($_SERVER['SCRIPT_NAME']) === 'escalate_api.php'
            );

            if ($is_api_request) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Database connection failed.',
                    'details' => $e->getMessage()
                ]);
            } else {
                http_response_code(500);
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Database Error | L2 Support Dashboard</title>
                    <script src="https://cdn.tailwindcss.com"></script>
                </head>
                <body class="bg-slate-900 text-slate-100 flex items-center justify-center min-h-screen p-4 font-sans">
                    <div class="max-w-md w-full bg-slate-800 border border-red-500/30 rounded-2xl p-6 shadow-2xl space-y-6">
                        <div class="flex items-center space-x-4 text-red-500">
                            <svg class="w-12 h-12 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                            </svg>
                            <div>
                                <h1 class="text-xl font-bold tracking-tight">Database Connection Failed</h1>
                                <p class="text-sm text-slate-400">PDO Connection Error</p>
                            </div>
                        </div>
                        <div class="bg-slate-950 p-4 rounded-lg font-mono text-xs text-red-400 overflow-x-auto border border-slate-700/50">
                            <?php echo htmlspecialchars($e->getMessage()); ?>
                        </div>
                        <div class="space-y-3 text-sm text-slate-300">
                            <h2 class="font-semibold text-slate-200">Troubleshooting Steps:</h2>
                            <ul class="list-disc list-inside space-y-1.5 text-slate-400">
                                <li>Ensure XAMPP/WAMP (MySQL/MariaDB) is running.</li>
                                <li>Verify that the <code class="text-cyan-400 font-mono text-xs bg-slate-950 px-1 py-0.5 rounded">support_dashboard</code> database exists.</li>
                                <li>Run <code class="text-cyan-400 font-mono text-xs bg-slate-950 px-1 py-0.5 rounded">schema.sql</code> to set up tables.</li>
                                <li>Check configuration in <code class="text-cyan-400 font-mono text-xs bg-slate-950 px-1 py-0.5 rounded">db.php</code>.</li>
                            </ul>
                        </div>
                    </div>
                </body>
                </html>
                <?php
            }
            exit;
        }
    }

    public static function getConnection() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->conn;
    }
}
