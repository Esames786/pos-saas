#!/usr/bin/env bash
# CP5 prep (stream B, HOST side, not elevated): build the three DEV-signed packages for the Windows Sandbox proof from ONE export
# of edge09/b-wizard (integ 2c951be4 + B's K5 CA fix), with a COPIED vendor (never a junction to E's closure: its optimized
# classmap would load the baseline App classes), strict layout, then audit each with the DEV public key.
#   0.9.0-dev  the integrated code (php + gateway + MySQL runtime)  -> wizard update target, fresh-install source
#   0.9.1-dev  + one trivial app change (php + gateway)             -> auto-update with real service control
#   0.9.2-dev  + edge:local:health deliberately broken              -> pointer rollback with real services
# DEV key only (C:\Users\Dell\BingooEdgeLab\e09\dev-signing, key id 68cbf5ba8376acc8). Never the release key.
# Export first:  git -C <b worktree> archive HEAD | tar -x -C <cp5>/src/tree   (vendor already copied into the tree)
set -u
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
CP5=${CP5_DIR:-/d/BingooEdgeLab-e09/cp5}   # owner rule 22 Nov: disposable packages / staging / evidence on D:
TREE=$CP5/src/tree
OUT=$(cygpath -m "$CP5/pkgs" 2>/dev/null || echo "D:/BingooEdgeLab-e09/cp5/pkgs")
KS='C:/Users/Dell/BingooEdgeLab/e09/dev-signing/edge-update-signing-dev-e09.keystore.json'
PASS='C:/Users/Dell/BingooEdgeLab/e09/dev-signing/edge-update-signing-dev-e09.passphrase'
VENDOR='C:/Users/Dell/BingooEdgeLab/e09/e/vendor-closure-7d7da307/vendor'
PHPRT='D:/laragon2/bin/php/php-8.3.16-Win32-vs16-x64'
GW='D:/laragon2/bin/nginx/nginx-1.22.0/nginx.exe'
MYSQL='D:/laragon2/bin/mysql/mysql-8.0.30-winx64'
DEVPUB=$(php -r 'echo json_decode(file_get_contents("C:/Users/Dell/BingooEdgeLab/e09/dev-signing/dev-public-facts.json"), true)["public_key"];')
LOGS=$CP5/build-logs
COMMIT=${CP5_COMMIT:-$(git -C /d/laragon2/www/pos-saas-e09-b rev-parse --short HEAD)}
cd "$TREE" || exit 1
[ -L vendor ] && { echo "STOP: vendor is a link - copy it"; exit 1; }

build() {
  local v=$1; shift
  local t0=$(date +%s)
  echo "=== build $v ($(date -Is))"
  EDGE_APP_VERSION=$v php artisan edge:build-package "$OUT/BingooEdge-$v" --force --allow-dirty --git-commit="b-$COMMIT-cp5-$v" --strict-layout \
    --signing-keystore="$KS" --signing-passphrase-file="$PASS" --vendor-from="$VENDOR" --php-runtime="$PHPRT" --gateway="$GW" "$@" \
    > "$LOGS/cp5-build-$v.log" 2>&1
  local rc=$?
  echo "build $v exit $rc in $(( $(date +%s) - t0 )) s"; if [ $rc -ne 0 ]; then echo "STOP: build $v failed"; exit 1; fi
  local t1=$(date +%s)
  EDGE_APP_VERSION=$v php artisan edge:audit-package "$OUT/BingooEdge-$v" --public-key="$DEVPUB" --require-signature --strict-layout --json > "$LOGS/cp5-audit-$v.json" 2>"$LOGS/cp5-audit-$v.err"
  echo "audit $v exit $? in $(( $(date +%s) - t1 )) s: $(php -r '$a=json_decode(file_get_contents($argv[1]),true); echo json_encode(["ok"=>$a["ok"]??null,"signature_ok"=>$a["signature_ok"]??null,"checksums_signature_ok"=>$a["checksums_signature_ok"]??null,"version"=>$a["edge_app_version"]??null,"files"=>$a["file_count"]??null,"package_hash"=>$a["package_hash"]??null]);' "$LOGS/cp5-audit-$v.json")"
}

build 0.9.0-dev --mysql-runtime="$MYSQL"

php -r '$f="app/Support/EdgeRuntime.php"; file_put_contents($f, file_get_contents($f) . "\n// CP5 rehearsal: 0.9.1-dev trivial app change (no behaviour change).\n");'
build 0.9.1-dev

php -r '$f="app/Console/Commands/EdgeLocalHealthCommand.php"; $s=file_get_contents($f); $a="    public function handle(EdgeApplianceHealthService \$health): int\n    {\n"; if (substr_count($s,$a)!==1) { fwrite(STDERR,"anchor\n"); exit(1);} file_put_contents($f, str_replace($a, $a . "        throw new \RuntimeException(\x27CP5 rehearsal: 0.9.2-dev deliberately broken health check\x27);\n", $s));' || { echo "STOP: health mutation"; exit 1; }
build 0.9.2-dev

# restore the export tree to the committed state (the Cloud in the sandbox runs from it)
git -C /d/laragon2/www/pos-saas-e09-b archive HEAD app/Support/EdgeRuntime.php app/Console/Commands/EdgeLocalHealthCommand.php | tar -x -C "$TREE"
echo "=== done $(date -Is)"
