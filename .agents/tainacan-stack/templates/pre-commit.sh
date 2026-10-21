#!/bin/sh
# tainacan-stack: gancho pre-commit (gerado por tnc-stack; reinstale com "tnc-stack gancho .")
# Verifica só o que está no índice. Bloqueio impede o commit; aviso não.

ROOT="$(git rev-parse --show-toplevel)"
CHECK="$ROOT/.agents/tainacan-stack/bin/tnc-check"
PHP_BIN="${TNC_PHP:-php}"

if [ -f "$CHECK" ]; then
  if command -v "$PHP_BIN" >/dev/null 2>&1 || [ -x "$PHP_BIN" ]; then
    "$PHP_BIN" "$CHECK" --staged --fail-on=block || {
      echo ""
      echo "tnc-check bloqueou o commit. Corrija ou justifique na linha com: // tnc-check-ignore TNC-XNN: motivo"
      exit 1
    }
  else
    echo "tnc-check: PHP não encontrado (defina TNC_PHP com o caminho do php). Verificação pulada." >&2
  fi
fi

# gancho que já existia antes da stack
if [ -x "$ROOT/.git/hooks/pre-commit.local" ]; then
  "$ROOT/.git/hooks/pre-commit.local" "$@" || exit $?
fi
exit 0
