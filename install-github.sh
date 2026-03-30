#!/usr/bin/env bash

set -euo pipefail

# Bootstrap installer intended to be executed from the Magento root,
# either after downloading this file or directly from the GitHub raw URL.

REPO_URL="${BOOKURIER_REPO_URL:-https://github.com/scarpelius/Shipping.git}"
BRANCH="${BOOKURIER_BRANCH:-main}"
MAGENTO_ROOT="${1:-$(pwd)}"
MODULE_DIR="${MAGENTO_ROOT}/app/code/Bookurier/Shipping"
MAGENTO_OWNER="$(stat -c '%U' "${MAGENTO_ROOT}")"
MAGENTO_RUN_USER="${BOOKURIER_RUN_USER:-${MAGENTO_OWNER}}"

run_magento() {
    if [[ "$(id -un)" = "${MAGENTO_RUN_USER}" ]]; then
        php bin/magento "$@"
        return
    fi

    if command -v sudo >/dev/null 2>&1; then
        sudo -u "${MAGENTO_RUN_USER}" php bin/magento "$@"
        return
    fi

    echo "Error: sudo is required to run Magento commands as ${MAGENTO_RUN_USER}." >&2
    exit 1
}

if [[ ! -f "${MAGENTO_ROOT}/bin/magento" ]]; then
    echo "Error: ${MAGENTO_ROOT} does not look like a Magento root. Expected bin/magento." >&2
    echo "Usage: $0 /path/to/magento/root" >&2
    exit 1
fi

if [[ "${EUID}" -eq 0 ]]; then
    echo "Error: do not run this installer as root." >&2
    echo "Running Magento CLI as root can leave cache/generated files inaccessible to the web server user." >&2
    echo "Run it as the Magento file owner or web user instead." >&2
    echo "Example: sudo -u ${MAGENTO_OWNER} bash install-github.sh ${MAGENTO_ROOT}" >&2
    exit 1
fi

if ! id "${MAGENTO_RUN_USER}" >/dev/null 2>&1; then
    echo "Error: Magento runtime user ${MAGENTO_RUN_USER} does not exist." >&2
    exit 1
fi

if [[ -e "${MODULE_DIR}" ]]; then
    echo "Error: ${MODULE_DIR} already exists." >&2
    echo "Remove it first if you want a clean GitHub install." >&2
    exit 1
fi

mkdir -p "${MAGENTO_ROOT}/app/code/Bookurier"

echo "Cloning ${REPO_URL} (${BRANCH}) into ${MODULE_DIR}"
git clone --branch "${BRANCH}" "${REPO_URL}" "${MODULE_DIR}"

cd "${MAGENTO_ROOT}"

echo "Enabling Bookurier module as ${MAGENTO_RUN_USER}"
run_magento module:enable Bookurier_Shipping

echo "Running setup upgrade as ${MAGENTO_RUN_USER}"
run_magento setup:upgrade

echo "Compiling dependency injection as ${MAGENTO_RUN_USER}"
run_magento setup:di:compile

echo "Flushing Magento cache as ${MAGENTO_RUN_USER}"
run_magento cache:flush

echo "Bookurier installation completed."
