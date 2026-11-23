# MySQL 8.0.30 for the Bingoo Edge Branch Server (offline install, Windows)

`mysql-8.0.30-winx64\` = the official MySQL Community zip distribution binaries (bin, share, include, docs, LICENSE, README),
the same build the LAB appliance has run since September 2026. No data directory, no config and no passwords are included.
Everything below runs on the Khatri PC only; it needs an ELEVATED PowerShell (Run as administrator) for the service steps.
Each step is a host change: the owner gives the GO before it runs.

## 1. Extract (not elevated)

```powershell
New-Item -ItemType Directory -Force C:\BingooEdge | Out-Null
Copy-Item -Recurse <USB>\mysql\mysql-8.0.30-winx64 C:\BingooEdge\mysql
Copy-Item <USB>\mysql\my.ini.template C:\BingooEdge\mysql\my.ini
& C:\BingooEdge\mysql\bin\mysqld.exe --version        # 8.0.30
```

## 2. Initialize the data directory (not elevated, once)

```powershell
& C:\BingooEdge\mysql\bin\mysqld.exe --defaults-file=C:\BingooEdge\mysql\my.ini --initialize-insecure --console
```
`--initialize-insecure` creates root WITHOUT a password only on loopback; step 4 sets it before anything else connects.

**`my.ini` must disable the binary log with `skip-log-bin`** — NOT `log_bin=OFF`: in MySQL 8 `log_bin=<name>` ENABLES the binlog
with the base name `OFF` (files `OFF.000001`… fill the data directory). Check before initializing: `Select-String -Path C:\BingooEdge\mysql\my.ini -Pattern 'log.bin'`
must show only `skip-log-bin` (found at Khatri 21 Nov 2026).

## 3. Register and start the Windows service (ELEVATED)

```powershell
& C:\BingooEdge\mysql\bin\mysqld.exe --install BingooEdgeMySQL --defaults-file=C:\BingooEdge\mysql\my.ini
Set-Service BingooEdgeMySQL -StartupType Automatic
Start-Service BingooEdgeMySQL
Get-Service BingooEdgeMySQL          # Running
Get-NetTCPConnection -State Listen -LocalPort 3306   # 127.0.0.1 only
```
If a MySQL already listens on 3306, either reuse it (owner decides) or set `port=3307` in `my.ini` and pass `-DbPort 3307` to the appliance installer.

## 4. Passwords from files, never on a command line (not elevated)

The owner writes two private files with Notepad (one line each, no trailing spaces):
`C:\BingooEdge\secrets\root.pass` and `C:\BingooEdge\secrets\edge.pass`. Then:

```powershell
$root = Get-Content C:\BingooEdge\secrets\root.pass -Raw
$edge = Get-Content C:\BingooEdge\secrets\edge.pass -Raw
$sql = @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$($root.Trim())';
CREATE DATABASE IF NOT EXISTS bingoo_edge_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'bingoo_edge'@'127.0.0.1' IDENTIFIED BY '$($edge.Trim())';
CREATE USER IF NOT EXISTS 'bingoo_edge'@'localhost' IDENTIFIED BY '$($edge.Trim())';
GRANT ALL PRIVILEGES ON bingoo_edge_local.* TO 'bingoo_edge'@'127.0.0.1', 'bingoo_edge'@'localhost';
FLUSH PRIVILEGES;
"@
$sql | & C:\BingooEdge\mysql\bin\mysql.exe -uroot -h127.0.0.1     # first connect: root has no password yet
Remove-Variable sql, root, edge
```
**`root@localhost` is NOT reachable over TCP when `my.ini` has `skip-name-resolve`** (the client connects as `root@127.0.0.1`,
which does not exist after `--initialize-insecure`, so the `$sql` block above is refused with `Access denied for user 'root'@'127.0.0.1'`).
Fix, as done on site 21 Nov 2026: create the TCP root account once through `mysqld --init-file` (ELEVATED; the service must be
stopped; the init file holds the root password, so it is written from `root.pass` and deleted straight after):

```powershell
Stop-Service BingooEdgeMySQL
$root = (Get-Content C:\BingooEdge\secrets\root.pass -Raw).Trim()
$init = "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '$root';`nGRANT ALL ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;`nFLUSH PRIVILEGES;"
Set-Content -Path C:\BingooEdge\secrets\init.sql -Value $init -Encoding ASCII
Remove-Variable init, root
# one-time foreground run; it prints "Execution of init_file ... started/ended" — then stop it with Ctrl+C
& C:\BingooEdge\mysql\bin\mysqld.exe --defaults-file=C:\BingooEdge\mysql\my.ini --init-file=C:\BingooEdge\secrets\init.sql --console
Remove-Item C:\BingooEdge\secrets\init.sql -Force          # never leave the init file behind
Start-Service BingooEdgeMySQL
```
Then run the `$sql` block above with `-uroot -h127.0.0.1 --password="$((Get-Content C:\BingooEdge\secrets\root.pass -Raw).Trim())"`
(root@127.0.0.1 now has the password; the `ALTER USER 'root'@'localhost'` line still sets the socket/named-pipe root).
`skip-name-resolve` stays in `my.ini` (it is correct for an appliance); only the extra `root@127.0.0.1` account is added.

The appliance installer takes `-DbUser bingoo_edge -DbPasswordFile C:\BingooEdge\secrets\edge.pass` and DELETES that file after
reading it (keep the password in the owner's password manager, not on the PC or the USB stick).
Connection check without typing the password: `& C:\BingooEdge\mysql\bin\mysql.exe -u bingoo_edge -h127.0.0.1 --password="$((Get-Content C:\BingooEdge\secrets\edge.pass -Raw).Trim())" -e "SELECT 1"`
(the value stays inside the PowerShell process; close that window afterwards).

## 5. Backups

The appliance's own `Backup-EdgeAppliance.ps1` / `Install-EdgeBackupTask.ps1` (package `scripts\`) dump this database hourly with
`mysqldump` from `C:\BingooEdge\mysql\bin`; restore with `Restore-EdgeAppliance.ps1`. Keep the MySQL root password and the
Edge recovery key in the owner's custody, never on the USB stick.

## Removal (ELEVATED, only if the install is abandoned)

```powershell
Stop-Service BingooEdgeMySQL; & C:\BingooEdge\mysql\bin\mysqld.exe --remove BingooEdgeMySQL
```
