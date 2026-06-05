# Airwell Plugin

Jeedom plugin to control Airwell air conditioners via the Gree protocol (local WiFi, no cloud required).

## Requirements

- Airwell air conditioner with a Gree WiFi module
- Jeedom 4.4+
- PHP 8.0+
- The air conditioner must be on the same local network as Jeedom

## Installation

Install the plugin from the Jeedom Market, then activate it.

## Device Configuration

1. Go to **Plugins > Comfort > Airwell**
2. Click **Add**
3. Enter the IP address and MAC address of the air conditioner
4. Click the binding icon to establish the connection
5. Save

## What to do if my devices are not found?

If the automatic import does not detect any air conditioner, follow these steps in order:

### 1. Make sure the AC is on the same Wi-Fi network as Jeedom

The Gree protocol only works on a **local network**. The air conditioner and the Jeedom server must be on the same subnet (e.g. `192.168.1.x`).

- Connect the air conditioner to Wi-Fi using the Airwell or Gree+ app.
- Check in your router/box that the device appears in the list of connected devices.
- If Jeedom runs on a separate VLAN, the UDP broadcast will not reach it — both devices must be on the same network segment.

### 2. Adjust the broadcast IP address

By default, the scan uses `255.255.255.255`. If your network is segmented, try the broadcast address of your subnet, for example `192.168.1.255`.

In the automatic import window, update the IP field before relaunching the scan.

### 3. Make sure the Wi-Fi module is properly initialised

Some Airwell/Gree Wi-Fi modules require initial setup through the official app before they can be discovered:

1. Download the **Gree+** or **Airwell Connected** app.
2. Add the device in the app (Wi-Fi pairing procedure).
3. Once the device is visible in the app, relaunch the scan from Jeedom.

### 4. Add the device manually

If the scan still does not work, you can add the device manually:

1. Find the IP address and MAC address of the air conditioner in your router/box.
2. Click **Add** in Jeedom.
3. Enter the IP and MAC, then click the binding icon to establish the connection.

## Available Commands

| Command | Type | Description |
|---|---|---|
| Power | Binary info | On/off state |
| Mode | String info | Active mode (auto/cool/heat/dry/fan_only) |
| Target temperature | Numeric info | Target temperature in °C |
| Fan speed | Numeric info | Fan speed level |
| Turn on | Action | Turns the air conditioner on |
| Turn off | Action | Turns the air conditioner off |
| Set temperature | Slider action | Sets the target temperature (16–30°C) |
| Set mode | Select action | Changes the operating mode |
