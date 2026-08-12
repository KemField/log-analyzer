<?php
/**
 * index.php
 * Interactive L2 Support Log Analyzer Dashboard
 * Displays log details, statistics, dynamic filtering, and escalation/resolution handlers.
 */

require_once 'db.php';

try {
    $db = Database::getConnection();
    
    // Fetch all logs joined with incident details
    $query = "
        SELECT l.*, i.priority AS incident_priority, i.developer_notes AS incident_notes 
        FROM logs l 
        LEFT JOIN incidents i ON l.id = i.log_id 
        ORDER BY l.timestamp DESC
    ";
    $stmt = $db->query($query);
    $logs = $stmt->fetchAll();
    
    // Calculate statistics
    $total_logs = count($logs);
    
    $critical_logs = 0;
    $open_incidents = 0;
    foreach ($logs as $log) {
        if ($log['severity'] === 'CRITICAL' || (int)$log['status_code'] === 500) {
            $critical_logs++;
        }
        if ($log['status'] === 'ESCALATED') {
            $open_incidents++;
        }
    }
} catch (Exception $e) {
    // If the database setup isn't complete, db.php handles rendering the error page.
    die();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>L2 Support Log Analyzer & Incident Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
        }
        code, pre {
            font-family: 'JetBrains Mono', monospace;
        }
        /* Custom scrollbar for slate panel */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #0f172a;
        }
        ::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col selection:bg-cyan-500/30 selection:text-cyan-200">

    <!-- Top Navigation Bar -->
    <nav class="bg-slate-900/60 backdrop-blur-xl border-b border-slate-800/80 sticky top-0 z-40 px-6 py-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="relative flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-cyan-500"></span>
                </div>
                <span class="text-lg font-bold text-white tracking-wide">L2 Support Analyzer</span>
                <span class="hidden md:inline bg-slate-800 text-slate-400 text-xs px-2.5 py-0.5 rounded-full border border-slate-700/50">Incident Dashboard</span>
            </div>
            <div class="flex items-center space-x-4">
                <button onclick="openImportModal()" class="px-4 py-2 text-xs font-semibold text-cyan-400 bg-cyan-400/10 border border-cyan-400/20 rounded-xl hover:bg-cyan-400/20 transition duration-200">
                    &orarr; Import Logs
                </button>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 md:p-8 space-y-8">
        
        <!-- Empty database setup check banner -->
        <div id="empty-state-banner" class="<?php echo $total_logs > 0 ? 'hidden' : ''; ?> bg-slate-900 border-2 border-dashed border-slate-800 rounded-3xl p-12 text-center max-w-2xl mx-auto my-12 shadow-2xl space-y-6">
            <div class="w-16 h-16 bg-cyan-500/10 border border-cyan-500/20 rounded-full flex items-center justify-center mx-auto text-cyan-400">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"></path>
                </svg>
            </div>
            <div class="space-y-2">
                <h2 class="text-2xl font-bold text-white">Database is Empty</h2>
                <p class="text-slate-400 text-sm max-w-md mx-auto">No server error logs found in the database. Open the import panel to parse standard server log files or paste custom error logs.</p>
            </div>
            <div>
                <button onclick="openImportModal()" class="inline-flex items-center gap-2 px-6 py-3 text-sm font-semibold text-slate-950 bg-cyan-400 hover:bg-cyan-350 rounded-xl shadow-lg shadow-cyan-500/20 transition duration-200">
                    Open Log Importer
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div id="dashboard-content" class="<?php echo $total_logs === 0 ? 'hidden' : ''; ?> space-y-8">
            <!-- Stats Overview Grid -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Card 1 -->
                <div class="bg-slate-900/50 backdrop-blur-md border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden group hover:border-slate-700/50 transition duration-300">
                    <div class="absolute -right-4 -bottom-4 text-slate-800/10 group-hover:scale-110 transition duration-300">
                        <svg class="w-32 h-32" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10H7v-2h10v2zm0-4H7V7h10v2zm0 8H7v-2h10v2z"/></svg>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Parsed Logs</p>
                            <h3 id="stat-total-logs" class="text-4xl font-extrabold text-white mt-2"><?php echo $total_logs; ?></h3>
                        </div>
                        <div class="p-3 bg-slate-800 rounded-xl border border-slate-700/50 text-slate-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Card 2 -->
                <div class="bg-slate-900/50 backdrop-blur-md border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden group hover:border-slate-700/50 transition duration-300">
                    <div class="absolute -right-4 -bottom-4 text-red-500/5 group-hover:scale-110 transition duration-300">
                        <svg class="w-32 h-32" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-red-400 uppercase tracking-wider">Critical Failures</p>
                            <h3 id="stat-critical-logs" class="text-4xl font-extrabold text-red-500 mt-2"><?php echo $critical_logs; ?></h3>
                        </div>
                        <div class="p-3 bg-red-950/30 rounded-xl border border-red-500/20 text-red-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Card 3 -->
                <div class="bg-slate-900/50 backdrop-blur-md border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden group hover:border-slate-700/50 transition duration-300">
                    <div class="absolute -right-4 -bottom-4 text-purple-500/5 group-hover:scale-110 transition duration-300">
                        <svg class="w-32 h-32" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13H5v-2h14v2z"/></svg>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-purple-400 uppercase tracking-wider">Escalated Incidents</p>
                            <h3 id="stat-open-incidents" class="text-4xl font-extrabold text-purple-400 mt-2"><?php echo $open_incidents; ?></h3>
                        </div>
                        <div class="p-3 bg-purple-950/30 rounded-xl border border-purple-500/20 text-purple-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-3-12v.75m0 3v.75m0 3v.75m0 3V18m-3-12v.75m0 3v.75m0 3v.75m0 3V18m-3-12v.75m0 3v.75m0 3v.75m0 3V18"/></svg>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Filters Section -->
            <section class="bg-slate-900/40 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                    <!-- Search Input -->
                    <div class="flex-1 relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" id="filter-search" placeholder="Filter message, file name, or client IP..." class="w-full bg-slate-950 text-slate-100 placeholder-slate-500 text-sm rounded-xl pl-10 pr-4 py-3 border border-slate-800 hover:border-slate-700 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 focus:outline-none transition duration-200">
                    </div>

                    <!-- Severity Dropdown -->
                    <div class="w-full lg:w-48">
                        <select id="filter-severity" class="w-full bg-slate-950 text-slate-100 text-sm rounded-xl px-4 py-3 border border-slate-800 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 focus:outline-none transition duration-200">
                            <option value="ALL">All Severities</option>
                            <option value="CRITICAL">CRITICAL</option>
                            <option value="ERROR">ERROR</option>
                            <option value="WARNING">WARNING</option>
                        </select>
                    </div>

                    <!-- Status Code Dropdown -->
                    <div class="w-full lg:w-48">
                        <select id="filter-status-code" class="w-full bg-slate-950 text-slate-100 text-sm rounded-xl px-4 py-3 border border-slate-800 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 focus:outline-none transition duration-200">
                            <option value="ALL">All Status Codes</option>
                            <?php 
                            $status_codes = array_unique(array_filter(array_column($logs, 'status_code')));
                            sort($status_codes);
                            foreach ($status_codes as $code):
                            ?>
                                <option value="<?php echo (int)$code; ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Reset Filters Button -->
                    <div>
                        <button id="btn-reset-filters" class="w-full lg:w-auto px-5 py-3 text-sm font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-750 border border-slate-700/50 rounded-xl transition duration-200">
                            Reset
                        </button>
                    </div>
                </div>
            </section>

            <!-- Workspace Tabs & Table -->
            <section class="space-y-4">
                <!-- Workspace Tabs -->
                <div class="flex border-b border-slate-800 pb-px">
                    <button onclick="switchWorkspaceTab('NEW')" id="w-tab-NEW" class="px-6 py-3.5 text-sm font-bold border-b-2 border-cyan-400 text-cyan-400 transition duration-200">
                        Inbox (NEW)
                    </button>
                    <button onclick="switchWorkspaceTab('ESCALATED')" id="w-tab-ESCALATED" class="px-6 py-3.5 text-sm font-bold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition duration-200">
                        Escalated
                    </button>
                    <button onclick="switchWorkspaceTab('RESOLVED')" id="w-tab-RESOLVED" class="px-6 py-3.5 text-sm font-bold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition duration-200">
                        Archive (RESOLVED)
                    </button>
                </div>

                <div class="bg-slate-900/30 border border-slate-850 rounded-2xl overflow-hidden shadow-2xl">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-900/80 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                    <th class="py-4 px-6">Timestamp & Client</th>
                                    <th class="py-4 px-4 text-center">Severity</th>
                                    <th class="py-4 px-4 text-center">Status Code</th>
                                    <th class="py-4 px-6 w-2/5">Error Message & Target File</th>
                                    <th class="py-4 px-4 text-center">Status</th>
                                    <th class="py-4 px-6 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="logs-table-body" class="divide-y divide-slate-850/60">
                                <?php foreach ($logs as $log): 
                                    // Determine severity style classes
                                    $severity_class = '';
                                    switch ($log['severity']) {
                                        case 'CRITICAL':
                                            $severity_class = 'bg-red-500/10 text-red-400 border border-red-500/20';
                                            break;
                                        case 'ERROR':
                                            $severity_class = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
                                            break;
                                        case 'WARNING':
                                            $severity_class = 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/20';
                                            break;
                                    }
                                    
                                    // Determine status style classes
                                    $status_class = '';
                                    switch ($log['status']) {
                                        case 'NEW':
                                            $status_class = 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
                                            break;
                                        case 'ESCALATED':
                                            $status_class = 'bg-purple-500/10 text-purple-400 border border-purple-500/20';
                                            break;
                                        case 'RESOLVED':
                                            $status_class = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                                            break;
                                    }
                                ?>
                                <tr class="hover:bg-slate-900/30 transition duration-150 log-row" 
                                    data-id="<?php echo (int)$log['id']; ?>"
                                    data-severity="<?php echo htmlspecialchars($log['severity']); ?>"
                                    data-status-code="<?php echo htmlspecialchars($log['status_code'] ?: ''); ?>"
                                    data-status="<?php echo htmlspecialchars($log['status']); ?>"
                                    data-search-text="<?php echo htmlspecialchars(strtolower($log['error_message'] . ' ' . $log['file_path'] . ' ' . $log['client_ip'])); ?>">
                                    
                                    <!-- Timestamp & Client -->
                                    <td class="py-4 px-6 space-y-1">
                                        <p class="text-sm text-slate-300 font-medium"><?php echo htmlspecialchars($log['timestamp']); ?></p>
                                        <div class="flex items-center space-x-1.5 text-xs text-slate-400">
                                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"></path>
                                            </svg>
                                            <span>IP: <?php echo htmlspecialchars($log['client_ip'] ?: 'N/A'); ?></span>
                                        </div>
                                    </td>

                                    <!-- Severity Badge -->
                                    <td class="py-4 px-4 text-center">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $severity_class; ?>">
                                            <?php if ($log['severity'] === 'CRITICAL'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400 mr-1.5 animate-pulse"></span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($log['severity']); ?>
                                        </span>
                                    </td>

                                    <!-- HTTP Code -->
                                    <td class="py-4 px-4 text-center">
                                        <?php if ($log['status_code']): ?>
                                            <span class="font-mono text-sm text-slate-200 bg-slate-900 border border-slate-800 px-2 py-0.5 rounded">
                                                <?php echo (int)$log['status_code']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-500 text-xs">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Error Message & File -->
                                    <td class="py-4 px-6 space-y-1">
                                        <p class="text-sm font-semibold text-white break-words line-clamp-2" title="<?php echo htmlspecialchars($log['error_message']); ?>">
                                            <?php echo htmlspecialchars($log['error_message']); ?>
                                        </p>
                                        <?php if ($log['file_path']): ?>
                                            <p class="text-xs text-cyan-400/80 font-mono break-all hover:text-cyan-300 transition duration-150">
                                                <?php echo htmlspecialchars($log['file_path']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <!-- If escalated, show priority and notes directly underneath -->
                                        <div class="escalation-details-box <?php echo $log['status'] !== 'ESCALATED' ? 'hidden' : ''; ?> mt-2 p-2.5 rounded-lg bg-purple-950/15 border border-purple-500/20 text-xs space-y-1">
                                            <div class="flex items-center gap-1 text-purple-400 font-bold">
                                                <span class="w-1 h-1 rounded-full bg-purple-400"></span>
                                                Escalation Details: <span class="incident-priority-label"><?php echo htmlspecialchars($log['incident_priority']); ?></span> Priority
                                            </div>
                                            <p class="incident-notes-label text-slate-350 italic break-words"><?php echo htmlspecialchars($log['incident_notes'] ?: 'No notes provided.'); ?></p>
                                        </div>
                                    </td>

                                    <!-- Status Badge -->
                                    <td class="py-4 px-4 text-center">
                                        <span class="status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($log['status']); ?>
                                        </span>
                                    </td>

                                    <!-- Actions -->
                                    <td class="action-cell py-4 px-6 text-right space-x-1.5 whitespace-nowrap">
                                        <?php if ($log['status'] === 'NEW'): ?>
                                            <button onclick="openEscalateModal(<?php echo (int)$log['id']; ?>, <?php echo htmlspecialchars(json_encode($log['error_message'])); ?>)" 
                                                    class="escalate-btn px-3 py-1.5 text-xs font-semibold text-slate-950 bg-cyan-400 hover:bg-cyan-350 rounded-lg hover:shadow-lg hover:shadow-cyan-500/10 transition duration-150">
                                                Escalate
                                            </button>
                                            <button onclick="resolveLog(<?php echo (int)$log['id']; ?>)" 
                                                    class="resolve-btn px-3 py-1.5 text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-750 border border-slate-700/50 rounded-lg transition duration-150">
                                                Resolve
                                            </button>
                                        <?php elseif ($log['status'] === 'ESCALATED'): ?>
                                            <button onclick="resolveLog(<?php echo (int)$log['id']; ?>)" 
                                                    class="resolve-btn px-3 py-1.5 text-xs font-semibold text-slate-950 bg-emerald-400 hover:bg-emerald-350 rounded-lg hover:shadow-lg hover:shadow-emerald-500/10 transition duration-150">
                                                Resolve
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500 font-semibold italic select-none">Resolved</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- No Rows Warning row -->
                                <tr id="no-matching-rows" class="hidden">
                                    <td colspan="6" class="py-12 text-center text-slate-500 text-sm">
                                        No error logs match your search filters in this tab.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- Import Logs Modal -->
    <div id="import-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div onclick="closeImportModal()" class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity duration-300"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl p-6 shadow-2xl flex flex-col space-y-6 scale-95 transition-all duration-300">
            <!-- Modal Header -->
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Import Server Log Entries
                </h3>
                <button onclick="closeImportModal()" class="text-slate-400 hover:text-white rounded-lg p-1 hover:bg-slate-800 transition duration-150">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Tabs Navigation -->
            <div class="flex border-b border-slate-800">
                <button onclick="switchImportTab('paste')" id="tab-btn-paste" class="flex-1 py-3 text-sm font-semibold border-b-2 border-cyan-500 text-cyan-400 transition duration-200">
                    Paste Raw Logs
                </button>
                <button onclick="switchImportTab('file')" id="tab-btn-file" class="flex-1 py-3 text-sm font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition duration-200">
                    Parse Sample File
                </button>
            </div>

            <!-- Tab 1: Paste Logs -->
            <div id="tab-content-paste" class="space-y-4">
                <div class="space-y-1.5">
                    <label for="import-raw-text" class="block text-xs font-semibold text-slate-400 uppercase tracking-wide">Raw Error Logs</label>
                    <textarea id="import-raw-text" rows="6" placeholder="Paste standard Apache, PHP or custom log lines here...&#10;Example:&#10;[Wed Aug 12 18:34:00.123456 2026] [php:error] [pid 1234] [client 192.168.1.99:56789] PHP Fatal error: Allowed memory size exhausted in C:\xampp\htdocs\mem.php on line 55" class="w-full bg-slate-950 text-slate-100 placeholder-slate-650 text-sm rounded-xl px-4 py-3 border border-slate-800 hover:border-slate-700 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 focus:outline-none transition duration-200 resize-none font-mono text-xs"></textarea>
                </div>
                <p class="text-xs text-slate-400">
                    Supports: XAMPP/Apache error formats, standard <code class="text-cyan-400 font-mono text-xs">php_errors.log</code> structures, and custom dashboard tokens.
                </p>
            </div>

            <!-- Tab 2: Parse File -->
            <div id="tab-content-file" class="hidden space-y-4 py-6 text-center">
                <div class="mx-auto w-12 h-12 bg-cyan-500/10 text-cyan-400 rounded-full flex items-center justify-center border border-cyan-500/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"></path>
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-white font-bold">Parse `sample_error.log` File</h4>
                    <p class="text-slate-400 text-xs max-w-sm mx-auto">This will scan the server-side log file and import any new, unique lines directly into the database.</p>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                <button type="button" onclick="closeImportModal()" class="px-5 py-2.5 text-xs font-bold text-slate-300 bg-slate-850 hover:bg-slate-800 border border-slate-700/50 rounded-xl transition duration-200">
                    Cancel
                </button>
                <button type="button" onclick="submitImport()" id="import-submit-btn" class="px-5 py-2.5 text-xs font-bold text-slate-950 bg-cyan-400 hover:bg-cyan-350 hover:shadow-lg hover:shadow-cyan-500/10 rounded-xl transition duration-200 flex items-center gap-2">
                    Import Logs
                </button>
            </div>
        </div>
    </div>

    <!-- Escalation Modal -->
    <div id="escalate-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div onclick="closeEscalateModal()" class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity duration-300"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg p-6 shadow-2xl flex flex-col space-y-6 scale-95 transition-all duration-300">
            <!-- Modal Header -->
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">
                    <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"></path>
                    </svg>
                    Escalate to Incident Ticket
                </h3>
                <button onclick="closeEscalateModal()" class="text-slate-400 hover:text-white rounded-lg p-1 hover:bg-slate-800 transition duration-150">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Log snippet info -->
            <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">Log Message Reference</span>
                <p id="modal-log-message" class="text-sm font-semibold text-slate-350 line-clamp-3 italic break-words"></p>
            </div>

            <!-- Escalation Form -->
            <form id="escalate-form" onsubmit="handleEscalateSubmit(event)" class="space-y-4">
                <input type="hidden" id="modal-log-id" name="log_id">

                <!-- Priority -->
                <div class="space-y-1.5">
                    <label for="escalate-priority" class="block text-xs font-semibold text-slate-400 uppercase tracking-wide">Assign Priority</label>
                    <select id="escalate-priority" name="priority" required class="w-full bg-slate-950 text-slate-100 text-sm rounded-xl px-4 py-3 border border-slate-800 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 focus:outline-none transition duration-200">
                        <option value="LOW">LOW - Minor Warning / UI glitched</option>
                        <option value="MEDIUM" selected>MEDIUM - Regular App Error / API issues</option>
                        <option value="HIGH">HIGH - Critical Outage / Data loss risk</option>
                    </select>
                </div>

                <!-- Developer Notes -->
                <div class="space-y-1.5">
                    <label for="escalate-notes" class="block text-xs font-semibold text-slate-400 uppercase tracking-wide">Developer Notes</label>
                    <textarea id="escalate-notes" name="developer_notes" required rows="4" placeholder="Explain the root cause analysis, context, or how developers should reproduce this error..." class="w-full bg-slate-950 text-slate-100 placeholder-slate-500 text-sm rounded-xl px-4 py-3 border border-slate-800 hover:border-slate-700 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 focus:outline-none transition duration-200 resize-none font-sans"></textarea>
                </div>

                <!-- Footer Actions -->
                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                    <button type="button" onclick="closeEscalateModal()" class="px-5 py-2.5 text-xs font-bold text-slate-300 bg-slate-850 hover:bg-slate-800 border border-slate-700/50 rounded-xl transition duration-200">
                        Cancel
                    </button>
                    <button type="submit" id="modal-submit-btn" class="px-5 py-2.5 text-xs font-bold text-slate-950 bg-cyan-400 hover:bg-cyan-350 hover:shadow-lg hover:shadow-cyan-500/10 rounded-xl transition duration-200 flex items-center gap-2">
                        Submit Escalation
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toast-container" class="fixed bottom-6 right-6 z-50 flex flex-col gap-3"></div>

    <!-- JavaScript Controllers -->
    <script>
        // Store references
        const filterSearch = document.getElementById('filter-search');
        const filterSeverity = document.getElementById('filter-severity');
        const filterStatusCode = document.getElementById('filter-status-code');
        const btnResetFilters = document.getElementById('btn-reset-filters');
        let logRows = document.querySelectorAll('.log-row');
        const noMatchingRows = document.getElementById('no-matching-rows');
        
        // Modal references
        const escalateModal = document.getElementById('escalate-modal');
        const modalLogId = document.getElementById('modal-log-id');
        const modalLogMessage = document.getElementById('modal-log-message');
        const escalateForm = document.getElementById('escalate-form');
        const escalatePriority = document.getElementById('escalate-priority');
        const escalateNotes = document.getElementById('escalate-notes');
        const modalSubmitBtn = document.getElementById('modal-submit-btn');

        // Stats elements references
        const statTotalLogs = document.getElementById('stat-total-logs');
        const statCriticalLogs = document.getElementById('stat-critical-logs');
        const statOpenIncidents = document.getElementById('stat-open-incidents');

        // Import Modal references
        const importModal = document.getElementById('import-modal');
        const tabBtnPaste = document.getElementById('tab-btn-paste');
        const tabBtnFile = document.getElementById('tab-btn-file');
        const tabContentPaste = document.getElementById('tab-content-paste');
        const tabContentFile = document.getElementById('tab-content-file');
        const importRawText = document.getElementById('import-raw-text');
        const importSubmitBtn = document.getElementById('import-submit-btn');
        
        // State variables
        let activeImportTab = 'paste'; // 'paste' or 'file'
        let activeWorkspaceTab = 'NEW'; // 'NEW' or 'ESCALATED' or 'RESOLVED'

        // Apply filters in real time
        function applyFilters() {
            const query = filterSearch ? filterSearch.value.trim().toLowerCase() : '';
            const severity = filterSeverity ? filterSeverity.value : 'ALL';
            const statusCode = filterStatusCode ? filterStatusCode.value : 'ALL';

            let visibleCount = 0;

            logRows.forEach(row => {
                const rowSeverity = row.getAttribute('data-severity') || '';
                const rowStatusCode = row.getAttribute('data-status-code') || '';
                const rowStatus = row.getAttribute('data-status') || '';
                const rowSearchText = row.getAttribute('data-search-text') || '';

                const matchesWorkspace = (rowStatus === activeWorkspaceTab);
                const matchesSearch = !query || rowSearchText.includes(query);
                const matchesSeverity = severity === 'ALL' || rowSeverity === severity;
                const matchesStatus = statusCode === 'ALL' || rowStatusCode === statusCode;

                if (matchesWorkspace && matchesSearch && matchesSeverity && matchesStatus) {
                    row.classList.remove('hidden');
                    visibleCount++;
                } else {
                    row.classList.add('hidden');
                }
            });

            if (noMatchingRows) {
                if (visibleCount === 0) {
                    noMatchingRows.classList.remove('hidden');
                } else {
                    noMatchingRows.classList.add('hidden');
                }
            }
        }

        // Switch Workspace Status Tab
        function switchWorkspaceTab(tab) {
            activeWorkspaceTab = tab;
            
            // Update Tab button highlights
            ['NEW', 'ESCALATED', 'RESOLVED'].forEach(status => {
                const btn = document.getElementById(`w-tab-${status}`);
                if (!btn) return;
                
                if (status === tab) {
                    btn.className = 'px-6 py-3.5 text-sm font-bold border-b-2 border-cyan-400 text-cyan-400 transition duration-200';
                } else {
                    btn.className = 'px-6 py-3.5 text-sm font-bold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition duration-200';
                }
            });
            
            applyFilters();
        }

        // Add filter listeners
        if (filterSearch) filterSearch.addEventListener('input', applyFilters);
        if (filterSeverity) filterSeverity.addEventListener('change', applyFilters);
        if (filterStatusCode) filterStatusCode.addEventListener('change', applyFilters);
        
        if (btnResetFilters) {
            btnResetFilters.addEventListener('click', () => {
                filterSearch.value = '';
                filterSeverity.value = 'ALL';
                filterStatusCode.value = 'ALL';
                applyFilters();
                showToast('Filters cleared', 'success');
            });
        }

        // Initial apply of filters
        switchWorkspaceTab('NEW');

        // Modal triggers
        function openEscalateModal(logId, logMessage) {
            modalLogId.value = logId;
            modalLogMessage.textContent = logMessage;
            
            // Show modal and apply animation classes
            escalateModal.classList.remove('hidden');
            setTimeout(() => {
                escalateModal.querySelector('.relative').classList.remove('scale-95');
                escalateModal.querySelector('.relative').classList.add('scale-100');
            }, 10);
        }

        function closeEscalateModal() {
            escalateModal.querySelector('.relative').classList.remove('scale-100');
            escalateModal.querySelector('.relative').classList.add('scale-95');
            setTimeout(() => {
                escalateModal.classList.add('hidden');
                escalateForm.reset();
            }, 150);
        }

        // Submit Escalation via AJAX Fetch API
        async function handleEscalateSubmit(event) {
            event.preventDefault();
            
            const logId = parseInt(modalLogId.value);
            const priority = escalatePriority.value;
            const notes = escalateNotes.value;

            // Update submit button state
            const originalBtnContent = modalSubmitBtn.innerHTML;
            modalSubmitBtn.disabled = true;
            modalSubmitBtn.innerHTML = `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-slate-950" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Processing...
            `;

            try {
                const response = await fetch('escalate_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        log_id: logId,
                        priority: priority,
                        developer_notes: notes
                    })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    showToast('Incident successfully escalated!', 'success');
                    
                    // Update UI state dynamically without reload
                    const row = document.querySelector(`.log-row[data-id="${logId}"]`);
                    if (row) {
                        // Change log state
                        row.setAttribute('data-status', 'ESCALATED');
                        row.dataset.status = 'ESCALATED';

                        // Update Status Badge in row
                        const badge = row.querySelector('.status-badge');
                        badge.className = 'status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-400 border border-purple-500/20';
                        badge.textContent = 'ESCALATED';

                        // Update Action Cell to show ONLY Resolve button
                        const actionCell = row.querySelector('.action-cell');
                        actionCell.innerHTML = `
                            <button onclick="resolveLog(${logId})" 
                                    class="resolve-btn px-3 py-1.5 text-xs font-semibold text-slate-950 bg-emerald-400 hover:bg-emerald-350 rounded-lg hover:shadow-lg hover:shadow-emerald-500/10 transition duration-150">
                                Resolve
                            </button>
                        `;

                        // Append Incident Details into row Error Message cell
                        const msgCell = row.cells[3]; // Error message cell
                        
                        // Find or create escalation details div
                        let detailsDiv = msgCell.querySelector('.escalation-details-box');
                        if (!detailsDiv) {
                            detailsDiv = document.createElement('div');
                            msgCell.appendChild(detailsDiv);
                        }
                        detailsDiv.className = 'escalation-details-box mt-2 p-2.5 rounded-lg bg-purple-950/15 border border-purple-500/20 text-xs space-y-1';
                        
                        // Sanitize priority and notes against XSS
                        const cleanPriority = escapeHTML(priority);
                        const cleanNotes = escapeHTML(notes);
                        
                        detailsDiv.innerHTML = `
                            <div class="flex items-center gap-1 text-purple-400 font-bold">
                                <span class="w-1 h-1 rounded-full bg-purple-400"></span>
                                Escalation Details: <span class="incident-priority-label">${cleanPriority}</span> Priority
                            </div>
                            <p class="incident-notes-label text-slate-350 italic break-words">${cleanNotes}</p>
                        `;
                    }

                    // Refresh Statistics Cards numbers
                    if (statOpenIncidents) {
                        const currentVal = parseInt(statOpenIncidents.textContent);
                        statOpenIncidents.textContent = currentVal + 1;
                    }
                    
                    // Close modal and shift row out of Inbox to Escalated tab
                    closeEscalateModal();
                    applyFilters();
                } else {
                    showToast(result.message || 'Escalation failed.', 'error');
                }
            } catch (error) {
                showToast('Network error: ' + error.message, 'error');
            } finally {
                // Restore button state
                modalSubmitBtn.disabled = false;
                modalSubmitBtn.innerHTML = originalBtnContent;
            }
        }

        // Submit Log Resolution via AJAX Fetch API
        async function resolveLog(logId) {
            if (!confirm('Are you sure you want to resolve and archive this log?')) return;
            
            try {
                const response = await fetch('resolve_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ log_id: logId })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    showToast('Log resolved and moved to Archive!', 'success');
                    
                    const row = document.querySelector(`.log-row[data-id="${logId}"]`);
                    if (row) {
                        const prevStatus = row.getAttribute('data-status') || '';
                        
                        // Set state to RESOLVED
                        row.setAttribute('data-status', 'RESOLVED');
                        row.dataset.status = 'RESOLVED';
                        
                        // Update status badge
                        const badge = row.querySelector('.status-badge');
                        badge.className = 'status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                        badge.textContent = 'RESOLVED';
                        
                        // Clear actions cell
                        const actionCell = row.querySelector('.action-cell');
                        actionCell.innerHTML = `<span class="text-xs text-slate-500 font-semibold italic select-none">Resolved</span>`;
                        
                        // Decrement incident count if we resolved an escalated log
                        if (prevStatus === 'ESCALATED' && statOpenIncidents) {
                            const currentVal = parseInt(statOpenIncidents.textContent);
                            statOpenIncidents.textContent = Math.max(0, currentVal - 1);
                        }
                        
                        // Refresh active filters (hides the row from active workspace tab)
                        applyFilters();
                    }
                } else {
                    showToast(result.message || 'Resolution failed.', 'error');
                }
            } catch (error) {
                showToast('Network error: ' + error.message, 'error');
            }
        }

        // Import Logs Modal Actions
        function openImportModal() {
            importModal.classList.remove('hidden');
            setTimeout(() => {
                importModal.querySelector('.relative').classList.remove('scale-95');
                importModal.querySelector('.relative').classList.add('scale-100');
            }, 10);
        }

        // Close Import Logs Modal
        function closeImportModal() {
            importModal.querySelector('.relative').classList.remove('scale-100');
            importModal.querySelector('.relative').classList.add('scale-95');
            setTimeout(() => {
                importModal.classList.add('hidden');
                importRawText.value = '';
                switchImportTab('paste');
            }, 150);
        }

        function switchImportTab(tab) {
            activeImportTab = tab;
            if (tab === 'paste') {
                tabBtnPaste.className = 'flex-1 py-3 text-sm font-semibold border-b-2 border-cyan-500 text-cyan-400 transition duration-200';
                tabBtnFile.className = 'flex-1 py-3 text-sm font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition duration-200';
                tabContentPaste.classList.remove('hidden');
                tabContentFile.classList.add('hidden');
                importSubmitBtn.textContent = 'Import Logs';
            } else {
                tabBtnFile.className = 'flex-1 py-3 text-sm font-semibold border-b-2 border-cyan-500 text-cyan-400 transition duration-200';
                tabBtnPaste.className = 'flex-1 py-3 text-sm font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition duration-200';
                tabContentFile.classList.remove('hidden');
                tabContentPaste.classList.add('hidden');
                importSubmitBtn.textContent = 'Parse Sample File';
            }
        }

        // Submit log import via AJAX
        async function submitImport() {
            let payload = {};
            if (activeImportTab === 'paste') {
                const text = importRawText.value.trim();
                if (!text) {
                    showToast('Please paste at least one log line.', 'error');
                    return;
                }
                payload = { action: 'parse_text', raw_text: text };
            } else {
                payload = { action: 'parse_file' };
            }

            // Button loading state
            const originalBtnContent = importSubmitBtn.innerHTML;
            importSubmitBtn.disabled = true;
            importSubmitBtn.innerHTML = `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-slate-950" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Parsing...
            `;

            try {
                const response = await fetch('parser.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    const stats = result.stats;
                    const insertedCount = stats.inserted;
                    const skippedCount = stats.duplicates_skipped;
                    const failedCount = stats.failed_regex;

                    if (insertedCount > 0) {
                        showToast(`Successfully imported ${insertedCount} logs! (${skippedCount} duplicates skipped)`, 'success');
                        
                        // If we are currently showing empty state, do a full reload to render table structure
                        const banner = document.getElementById('empty-state-banner');
                        if (banner && !banner.classList.contains('hidden')) {
                            window.location.reload();
                            return;
                        }

                        // Prepend newly inserted logs to table in real-time
                        if (result.inserted_logs && result.inserted_logs.length > 0) {
                            prependLogsToTable(result.inserted_logs);
                        }

                        // Update statistical numbers
                        if (statTotalLogs) {
                            statTotalLogs.textContent = parseInt(statTotalLogs.textContent) + insertedCount;
                        }
                        if (statCriticalLogs) {
                            let newCriticalCount = 0;
                            result.inserted_logs.forEach(log => {
                                if (log.severity === 'CRITICAL' || parseInt(log.status_code) === 500) {
                                    newCriticalCount++;
                                }
                            });
                            statCriticalLogs.textContent = parseInt(statCriticalLogs.textContent) + newCriticalCount;
                        }
                    } else if (skippedCount > 0) {
                        showToast(`Skipped: All ${skippedCount} log lines were duplicate entries.`, 'success');
                    } else if (failedCount > 0) {
                        showToast(`Parsing failed: ${failedCount} lines did not match format.`, 'error');
                    } else {
                        showToast('No logs were parsed.', 'error');
                    }
                    closeImportModal();
                } else {
                    showToast(result.message || 'Parsing failed.', 'error');
                }
            } catch (error) {
                showToast('Network error: ' + error.message, 'error');
            } finally {
                importSubmitBtn.disabled = false;
                importSubmitBtn.innerHTML = originalBtnContent;
            }
        }

        // Dynamically prepend rows in Vanilla JS
        function prependLogsToTable(newLogs) {
            const tableBody = document.getElementById('logs-table-body');
            if (!tableBody) return;

            // Hide "no matching rows" row if showing
            if (noMatchingRows) noMatchingRows.classList.add('hidden');

            // Prepend in reverse-chrono order
            newLogs.forEach(log => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-900/30 transition duration-150 log-row';
                tr.setAttribute('data-id', log.id);
                tr.setAttribute('data-severity', log.severity);
                tr.setAttribute('data-status-code', log.status_code || '');
                tr.setAttribute('data-status', 'NEW');
                tr.setAttribute('data-search-text', `${log.error_message} ${log.file_path || ''} ${log.client_ip || ''}`.toLowerCase());

                // Keep dataset for compatibility
                tr.dataset.id = log.id;
                tr.dataset.severity = log.severity;
                tr.dataset.statusCode = log.status_code || '';
                tr.dataset.status = 'NEW';
                tr.dataset.searchText = `${log.error_message} ${log.file_path || ''} ${log.client_ip || ''}`.toLowerCase();

                // Severity Badge styles
                let severityClass = '';
                let severityPulse = '';
                if (log.severity === 'CRITICAL') {
                    severityClass = 'bg-red-500/10 text-red-400 border border-red-500/20';
                    severityPulse = '<span class="w-1.5 h-1.5 rounded-full bg-red-400 mr-1.5 animate-pulse"></span>';
                } else if (log.severity === 'ERROR') {
                    severityClass = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
                } else {
                    severityClass = 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/20';
                }

                // HTTP Status code cell
                const codeCell = log.status_code 
                    ? `<span class="font-mono text-sm text-slate-200 bg-slate-900 border border-slate-800 px-2 py-0.5 rounded">${log.status_code}</span>`
                    : '<span class="text-slate-500 text-xs">-</span>';

                // File path cell
                const fileCell = log.file_path 
                    ? `<p class="text-xs text-cyan-400/80 font-mono break-all hover:text-cyan-300 transition duration-150">${escapeHTML(log.file_path)}</p>` 
                    : '';

                tr.innerHTML = `
                    <td class="py-4 px-6 space-y-1">
                        <p class="text-sm text-slate-300 font-medium">${escapeHTML(log.timestamp)}</p>
                        <div class="flex items-center space-x-1.5 text-xs text-slate-400">
                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"></path>
                            </svg>
                            <span>IP: ${escapeHTML(log.client_ip || 'N/A')}</span>
                        </div>
                    </td>
                    <td class="py-4 px-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${severityClass}">
                            ${severityPulse}
                            ${escapeHTML(log.severity)}
                        </span>
                    </td>
                    <td class="py-4 px-4 text-center">
                        ${codeCell}
                    </td>
                    <td class="py-4 px-6 space-y-1">
                        <p class="text-sm font-semibold text-white break-words line-clamp-2" title="${escapeHTML(log.error_message)}">
                            ${escapeHTML(log.error_message)}
                        </p>
                        ${fileCell}
                        <div class="escalation-details-box hidden mt-2 p-2.5 rounded-lg bg-purple-950/15 border border-purple-500/20 text-xs space-y-1">
                            <div class="flex items-center gap-1 text-purple-400 font-bold">
                                <span class="w-1 h-1 rounded-full bg-purple-400"></span>
                                Escalation Details: <span class="incident-priority-label"></span> Priority
                            </div>
                            <p class="incident-notes-label text-slate-350 italic break-words"></p>
                        </div>
                    </td>
                    <td class="py-4 px-4 text-center">
                        <span class="status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                            NEW
                        </span>
                    </td>
                    <td class="action-cell py-4 px-6 text-right space-x-1.5 whitespace-nowrap">
                        <button onclick="openEscalateModal(${log.id}, ${JSON.stringify(log.error_message)})" 
                                class="escalate-btn px-3 py-1.5 text-xs font-semibold text-slate-950 bg-cyan-400 hover:bg-cyan-350 rounded-lg hover:shadow-lg hover:shadow-cyan-500/10 transition duration-150">
                            Escalate
                        </button>
                        <button onclick="resolveLog(${log.id})" 
                                class="resolve-btn px-3 py-1.5 text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-750 border border-slate-700/50 rounded-lg transition duration-150">
                            Resolve
                        </button>
                    </td>
                `;

                // Prepend log to the start of the table body
                tableBody.insertBefore(tr, tableBody.firstChild);
            });

            // Re-fetch lists of rows so live search filters apply to them
            logRows = document.querySelectorAll('.log-row');
            applyFilters();
        }

        // Helper to sanitize dynamic UI updates
        function escapeHTML(str) {
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>
