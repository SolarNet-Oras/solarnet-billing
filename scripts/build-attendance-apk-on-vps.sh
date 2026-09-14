#!/bin/sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
signing_dir=/root/solarnet-attendance-signing
keystore="$signing_dir/solarnet-attendance-release.jks"
secret_file="$signing_dir/android-build.env"
output_dir="$project_root/frontend/public"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this script as root so the private signing key remains protected." >&2
    exit 1
fi
if [ ! -f "$keystore" ]; then
    echo "Signing key not found: $keystore" >&2
    exit 1
fi

printf 'Keystore password: ' >&2
stty -echo
IFS= read -r store_password
stty echo
printf '\nKey password (usually the same): ' >&2
stty -echo
IFS= read -r key_password
stty echo
printf '\n' >&2

umask 077
printf '%s\n' \
    "SOLARNET_ANDROID_STORE_PASSWORD=$store_password" \
    "SOLARNET_ANDROID_KEY_PASSWORD=$key_password" \
    'SOLARNET_ANDROID_KEY_ALIAS=solarnet-attendance' \
    'SOLARNET_ANDROID_KEYSTORE=/signing/solarnet-attendance-release.jks' \
    > "$secret_file"
unset store_password key_password

cleanup() { rm -f "$secret_file"; }
trap cleanup EXIT HUP INT TERM

docker build -t solarnet-attendance-android-builder -f "$project_root/android-attendance/Dockerfile.build" "$project_root/android-attendance"
docker run --rm \
    --env-file "$secret_file" \
    -v "$project_root:/workspace" \
    -v "$signing_dir:/signing:ro" \
    -v "$output_dir:/output" \
    solarnet-attendance-android-builder

chmod 0644 "$output_dir/solarnet-attendance.apk"
echo "Signed APK created: $output_dir/solarnet-attendance.apk"
