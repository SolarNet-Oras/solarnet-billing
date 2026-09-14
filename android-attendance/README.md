# SolarNet Attendance Android

Dedicated Android wrapper for `https://attendance.solarnetportal.com/attendance-app/`.
It permits only the Attendance origin inside the app. External links open in the
device browser. Billing, staff administration, and the customer portal are not
included.

Release builds require `SOLARNET_ANDROID_KEYSTORE`,
`SOLARNET_ANDROID_STORE_PASSWORD`, `SOLARNET_ANDROID_KEY_ALIAS`, and
`SOLARNET_ANDROID_KEY_PASSWORD`. Keep the keystore permanently; Android will not
accept future updates signed with a different key.
