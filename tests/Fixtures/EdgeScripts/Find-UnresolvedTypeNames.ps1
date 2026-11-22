# Edge 0.9 guard (CP5 run 12): every namespace-qualified .NET type a script under -Root names - New-Object <Type> and
# [Type] literals / casts - must resolve on Windows PowerShell 5.1. "New-Object Text.Encoding.ASCIIEncoding" (no such type)
# sat in the wizard's fresh-install MySQL step until a real fresh install in Windows Sandbox reached it.
# Output: one "<file>:<line> <type>" per unresolved name, then "unresolved: <n>". Exit 0 = none.
param([Parameter(Mandatory = $true)][string]$Root)
$ErrorActionPreference = 'Stop'
foreach ($a in 'PresentationFramework', 'PresentationCore', 'WindowsBase', 'System.Windows.Forms', 'System.Drawing', 'System.Security',
    'System.Net.Http', 'System.IO.Compression', 'System.IO.Compression.FileSystem', 'Microsoft.VisualBasic', 'UIAutomationClient',
    'UIAutomationTypes', 'System.Web.Extensions') { try { Add-Type -AssemblyName $a -ErrorAction Stop } catch { } }
$root = (Resolve-Path -LiteralPath $Root).Path.TrimEnd('\')
$bad = New-Object System.Collections.ArrayList
foreach ($f in @(Get-ChildItem -LiteralPath $root -Recurse -File | Where-Object { @('.ps1', '.psm1') -contains $_.Extension })) {
    $tokens = $null; $errs = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($f.FullName, [ref]$tokens, [ref]$errs)
    # types a script defines itself (Add-Type -TypeDefinition / PowerShell classes) are not resolvable before it runs
    $own = @([regex]::Matches([IO.File]::ReadAllText($f.FullName), '(?:class|enum|struct|interface)\s+(\w+)') | ForEach-Object { $_.Groups[1].Value })
    $names = New-Object System.Collections.ArrayList
    foreach ($c in $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'New-Object' }, $true)) {
        $els = $c.CommandElements
        for ($i = 1; $i -lt $els.Count; $i++) {
            $e = $els[$i]
            if ($e -is [System.Management.Automation.Language.CommandParameterAst]) {
                if ($e.ParameterName -eq 'ComObject') { break }
                if ($e.ParameterName -match '^(Property|ArgumentList)$' -and -not $e.Argument) { $i++ }
                continue
            }
            if ($e -is [System.Management.Automation.Language.StringConstantExpressionAst]) { [void]$names.Add(@($e.Value, $e.Extent.StartLineNumber)) }
            break
        }
    }
    foreach ($t in $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.TypeExpressionAst] -or $n -is [System.Management.Automation.Language.TypeConstraintAst] }, $true)) {
        [void]$names.Add(@($t.TypeName.FullName, $t.Extent.StartLineNumber))
    }
    foreach ($n in $names) {
        $name = [string]$n[0]
        if ($name -notmatch '\.') { continue }   # [string], [int], accelerators: always resolve
        if ($own -contains (($name -replace '\[.*$', '') -split '\.')[-1]) { continue }
        if (($name -as [type]) -or (('System.' + $name) -as [type])) { continue }
        [void]$bad.Add(('{0}:{1} {2}' -f $f.FullName.Substring($root.Length + 1), $n[1], $name))
    }
}
$bad | Sort-Object -Unique
"unresolved: $($bad.Count)"
if ($bad.Count) { exit 1 }
exit 0
