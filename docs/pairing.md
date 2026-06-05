# Airwell / Gree — Local Pairing Process

Technical reference for implementing the device pairing flow in Jeedom.
Validated on firmware **V3.4.M** (WiFi module `U-WB05RT13V1.44`).

---

## 1. Protocol Overview

Airwell air conditioners use the **Gree WiFi protocol**: JSON payloads exchanged over **UDP port 7000**.

All payloads are encrypted. Two cipher versions exist depending on the WiFi module firmware:

| Cipher | Mode | Key | When |
|--------|------|-----|------|
| **V1** | AES-128-ECB, PKCS7 | `a3K8Bx%2r8Y7#xDh` (generic) | Module firmware < v1.21 |
| **V2** | AES-128-GCM | `{yxAHAY_Lm6pbC/<` (generic) | Module firmware ≥ v1.21 |

V2 fixed parameters:
- **Nonce**: `\x54\x40\x78\x44\x49\x67\x5a\x51\x6c\x5e\x63\x13` (12 bytes, non-zero)
- **AAD**: `qualcomm-test`
- **Tag length**: 16 bytes

The module firmware version is returned in the `hid` field of the scan response (e.g. `U-WB05RT13V1.44`). Since parsing this is fragile, the implementation **tries V1 first, then falls back to V2** on timeout.

> **Note**: Airwell devices sold in France use module firmware ≥ v1.21 — cipher V2 is the expected path.

---

## 2. Packet Envelope

Every request sent to the device is wrapped in an outer JSON envelope:

```json
{
  "t":    "pack",
  "i":    1,
  "uid":  0,
  "cid":  "app",
  "tcid": "<device_mac>",
  "pack": "<base64_encrypted_inner_payload>",
  "tag":  "<base64_gcm_tag>"
}
```

- `"i"` is `1` for **scan** and **bind**, `0` for **status** and **cmd**.
- `"tag"` is only present for V2 (GCM) packets; omit entirely for V1.
- `"cid"` is always `"app"` for client-initiated requests.
- `"tcid"` is the target device MAC address (lowercase hex, no colons, e.g. `7cb8e6251c43`).

Responses from the device follow the same envelope structure. The inner `pack` must be decrypted with the appropriate key and cipher.

---

## 3. Step 1 — Discovery (UDP Broadcast)

**Purpose**: find devices on the local network and retrieve their IP and MAC.

### Request

Send a plain (unencrypted) JSON string as a UDP broadcast on port 7000:

```json
{"t": "scan"}
```

- Source: any ephemeral port (no need to bind to 7000)
- Destination: broadcast address (e.g. `192.168.1.255:7000`)
- Listen for 3 seconds and collect all responses

### Response (one per device)

The outer envelope is plain JSON; the `pack` is encrypted with **V1 generic key** regardless of the device's cipher version:

```json
{
  "t":    "pack",
  "i":    1,
  "uid":  0,
  "cid":  "<device_mac>",
  "tcid": "",
  "pack": "<base64_v1_encrypted>"
}
```

Decrypting `pack` with V1 generic key yields:

```json
{
  "t":         "dev",
  "cid":       "7cb8e6251c43",
  "mac":       "7cb8e6251c43",
  "name":      "",
  "brand":     "gree",
  "ver":       "V3.4.M",
  "hid":       "362001065279+U-WB05RT13V1.44.bin",
  "ModelType": "2168684544",
  "lock":      0
}
```

### Data to retain

| Field | Source | Used for |
|-------|--------|----------|
| `ip` | UDP source address | All subsequent unicast requests |
| `mac` | `pack.mac` or `pack.cid` | All subsequent requests |

---

## 4. Step 2 — Bind (Key Exchange)

**Purpose**: retrieve the device-specific encryption key used for all subsequent commands.

The generic key is used only for this step. After a successful bind, all communications use the **device key**.

### Cipher detection strategy

Try V1 first (5 s timeout). If no response, try V2 (5 s timeout). Store the cipher version that succeeded alongside the device key.

### Request (inner payload, encrypted with generic key)

```json
{
  "mac": "7cb8e6251c43",
  "t":   "bind",
  "uid": 0
}
```

Encrypt with:
- **V1**: AES-128-ECB + V1 generic key → `pack` only, no `tag`
- **V2**: AES-128-GCM + V2 generic key → `pack` + `tag`

Outer envelope: `"i": 1`

### Response (inner payload, decrypted with generic key + same cipher)

```json
{
  "t":   "bindok",
  "mac": "7cb8e6251c43",
  "key": "a3leZEM18rCM8ZYv"
}
```

### Data to persist in Jeedom equipment config

| Config key | Value | Example |
|------------|-------|---------|
| `ip` | Device IP (from discovery) | `192.168.1.152` |
| `mac` | Device MAC | `7cb8e6251c43` |
| `device_key` | `bindok.key` | `a3leZEM18rCM8ZYv` |
| `cipher` | `v1` or `v2` | `v2` |

> The bind can be re-run at any time to refresh the key. It does not require resetting the device.

---

## 5. Step 3 — Read Status

**Purpose**: poll the current state of the device (power, mode, temperature, fan speed, …).

Encrypt with the **device key** and the **stored cipher version**.

### Request (inner payload)

```json
{
  "mac":  "7cb8e6251c43",
  "t":    "status",
  "cols": ["Pow", "Mod", "SetTem", "WdSpd", "Air", "Blo", "Health",
           "SwhSlp", "Lig", "SwingLfRig", "SwUpDn", "Quiet", "Tur",
           "StHt", "TemUn", "HeatCoolType", "TemRec", "SvSt"]
}
```

Outer envelope: `"i": 0`

### Response (inner payload)

```json
{
  "t":    "dat",
  "mac":  "7cb8e6251c43",
  "cols": ["Pow", "Mod", "SetTem", "WdSpd", ...],
  "dat":  [1,     3,     27,       1,       ...]
}
```

Build a key→value map: `array_combine(cols, dat)`.

### Key parameters

| Parameter | Type | Values |
|-----------|------|--------|
| `Pow` | int | `0` = off, `1` = on |
| `Mod` | int | `0`=auto `1`=cool `2`=dry `3`=fan_only `4`=heat |
| `SetTem` | int | 16–30 °C |
| `WdSpd` | int | `0`=auto `1`–`5`=speed |
| `TemRec` | int | Ambient temperature reading (sensor) |

---

## 6. Step 4 — Send Command

**Purpose**: change one or more device parameters.

Encrypt with the **device key** and the **stored cipher version**.

### Request (inner payload)

```json
{
  "t":   "cmd",
  "opt": ["Pow", "Mod", "SetTem"],
  "p":   [1,     1,     22]
}
```

`opt` and `p` are parallel arrays: same index = same parameter.
Outer envelope: `"i": 0`

### Response (inner payload)

```json
{
  "t":   "ack",
  "opt": ["Pow", "Mod", "SetTem"],
  "p":   [1,     1,     22],
  "r":   200
}
```

`r = 200` means the command was accepted. Any other value is an error.

---

## 7. Jeedom Integration Points

### Equipment configuration keys

| Key | Set by | Used by |
|-----|--------|---------|
| `ip` | User (UI form) | All protocol calls |
| `mac` | User (UI form) | All protocol calls |
| `device_key` | Bind action | `getStatus`, `sendCommand` |
| `cipher` | Bind action | `getStatus`, `sendCommand` |

### Cron cycle (every 5 min)

`airwell::cron5()` → `$eqLogic->refreshStatus()` → `GreeProtocol::getStatus()` → `checkAndUpdateCmd()`

### Action commands → Gree parameters

| Jeedom logicalId | Option key | Gree params |
|-----------------|------------|-------------|
| `turn_on` | — | `Pow=1` |
| `turn_off` | — | `Pow=0` |
| `set_temperature` | `slider` | `SetTem=(int)value` |
| `set_mode` | `select` | `Mod=MODES[value]` |

Mode mapping: `auto→0, cool→1, dry→2, fan_only→3, heat→4`

### Bind trigger

Exposed as a button in the Jeedom UI (`bt_bindDevice`) → AJAX `bindDevice` action → `GreeProtocol::bind()` → persists `device_key` + `cipher` in equipment config.

---

## 8. Implementation Notes

- **UDP socket**: use `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` with `socket_bind($sock, '0.0.0.0', 0)`. The `fsockopen('udp://...')` approach does not reliably receive responses.
- **Broadcast** requires `socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1)`.
- **Timeout**: set via `SO_RCVTIMEO` option, not stream timeout. Use 3 s for discovery, 5 s for bind/status/cmd.
- **MAC format**: lowercase hex without colons (e.g. `7cb8e6251c43`), as returned by the scan response.
- **V2 tag in response**: when decrypting a V2 response, the `tag` field from the outer envelope must be passed to `openssl_decrypt` as the GCM authentication tag.
- **Re-bind safety**: the bind can be repeated without resetting the device. Each successful bind may return a different key; always update the stored key.
