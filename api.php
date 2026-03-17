<?php
header('Content-Type: application/json');

function get_server_stats() {
    // CPU Usage (Linux)
    $load = sys_getloadavg();
    $cpu_usage = $load[0] * 100 / shell_exec('nproc');

    // Memory Usage
    $free = shell_exec('free -m');
    $free = (string)trim($free);
    $free_arr = explode("\n", $free);
    $mem = explode(" ", preg_replace('/\s+/', ' ', $free_arr[1]));
    $total_mem = $mem[1];
    $used_mem = $mem[2];
    $mem_usage = round(($used_mem / $total_mem) * 100, 2);

    // Disk Usage
    $disk_total = disk_total_space("/");
    $disk_free = disk_free_space("/");
    $disk_used = $disk_total - $disk_free;
    $disk_usage = round(($disk_used / $disk_total) * 100, 2);

    // Uptime
    $uptime = shell_exec('uptime -p');

    return [
        'cpu' => round($cpu_usage, 2),
        'memory' => [
            'percentage' => $mem_usage,
            'total' => round($total_mem / 1024, 2) . ' GB',
            'used' => round($used_mem / 1024, 2) . ' GB'
        ],
        'disk' => [
            'percentage' => $disk_usage,
            'total' => round($disk_total / (1024**3), 2) . ' GB',
            'used' => round($disk_used / (1024**3), 2) . ' GB'
        ],
        'uptime' => trim($uptime),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

echo json_encode(get_server_stats());
