<?php

namespace OPNsense\Interfaces\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Config;
use OPNsense\Core\Backend;

class AssignSettingsController extends ApiControllerBase
{
    public function addItemAction()
    {
        // Read JSON robustly
        $payload = $this->request->getJsonRawBody(true);
        if (empty($payload) || !is_array($payload)) {
            $payload = json_decode($this->request->getRawBody(), true);
        }
        if (empty($payload) || !is_array($payload)) {
            return ["result" => "failed", "message" => "Invalid JSON body"];
        }

        $assign = $payload['assign'] ?? $payload;
        $device = $assign['device'] ?? null;
        $description = $assign['description'] ?? null;

        if (empty($device) || empty($description)) {
            return [
                "result" => "failed",
                "message" => "Missing device or description",
                "debug" => ["received" => $payload]
            ];
        }

        $cfg = Config::getInstance()->object();

        // Ensure <interfaces> exists
        if (!isset($cfg->interfaces)) {
            $cfg->addChild('interfaces');
        }
        $interfaces = $cfg->interfaces;

        // Find next optX
        $optIndex = 1;
        while (isset($interfaces->{'opt' . $optIndex})) {
            $optIndex++;
        }
        $newIf = 'opt' . $optIndex;

        // Create <optX> and children using XML-safe addChild
        $opt = $interfaces->addChild($newIf);
        $opt->addChild('if', (string)$device);
        $opt->addChild('descr', (string)$description);
        $opt->addChild('enable', "1");

        Config::getInstance()->save();

        return [
            "result" => "saved",
            "interface" => $newIf,
            "device" => (string)$device
        ];
    }

    // NOTE: camelCase method name -> endpoint /set_ipv4
    public function setIpv4Action()
    {
        // Read JSON robustly
        $payload = $this->request->getJsonRawBody(true);
        if (empty($payload) || !is_array($payload)) {
            $payload = json_decode($this->request->getRawBody(), true);
        }
        if (empty($payload) || !is_array($payload)) {
            return ["result" => "failed", "message" => "Invalid JSON body"];
        }

        $id   = $payload['identifier'] ?? null;
        $type = $payload['ipv4_type'] ?? 'static';
        $ip   = $payload['ipaddr'] ?? null;
        $cidr = $payload['cidr'] ?? null;
        $gw   = $payload['gateway'] ?? null;

        // validate
        if (!preg_match('/^opt\d+$/', (string)$id)) {
            return ["result" => "failed", "message" => "invalid identifier"];
        }

        $cidrInt = null;
        if ($type === 'static') {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return ["result" => "failed", "message" => "invalid ipaddr"];
            }
            $cidrInt = intval($cidr);
            if ($cidrInt < 0 || $cidrInt > 32) {
                return ["result" => "failed", "message" => "invalid cidr"];
            }
            if (!empty($gw) && !filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return ["result" => "failed", "message" => "invalid gateway"];
            }
        } elseif ($type !== 'none') {
            return ["result" => "failed", "message" => "invalid ipv4_type (use static/none)"];
        }

        $cfg = Config::getInstance()->object();
        if (!isset($cfg->interfaces->{$id})) {
            return ["result" => "failed", "message" => "interface not found"];
        }

        $iface = $cfg->interfaces->{$id};
        $iface->enable = "1";

        // clean existing nodes biar gak duplicate
        unset($iface->ipaddr);
        unset($iface->subnet);
        unset($iface->gateway);

        if ($type === 'static') {
            $iface->addChild('ipaddr', (string)$ip);
            $iface->addChild('subnet', (string)$cidrInt);
            if (!empty($gw)) {
                $iface->addChild('gateway', (string)$gw);
            }
        }

        // optional flags (kalau dikirim)
        if (array_key_exists('block_private', $payload)) {
            unset($iface->blockpriv);
            $iface->addChild('blockpriv', $payload['block_private'] ? "1" : "0");
        }
        if (array_key_exists('block_bogon', $payload)) {
            unset($iface->blockbogons);
            $iface->addChild('blockbogons', $payload['block_bogon'] ? "1" : "0");
        }
        if (array_key_exists('mtu', $payload)) {
            unset($iface->mtu);
            if ($payload['mtu'] !== "") {
                $iface->addChild('mtu', (string)$payload['mtu']);
            }
        }
        if (array_key_exists('mss', $payload)) {
            unset($iface->mss);
            if ($payload['mss'] !== "") {
                $iface->addChild('mss', (string)$payload['mss']);
            }
        }

        Config::getInstance()->save();

        // reload interfaces via configd
        try {
            $backend = new Backend();
            $backend->configdRun("interface reload");
        } catch (\Throwable $e) {
            return [
                "result" => "failed",
                "message" => "backend error",
                "detail" => $e->getMessage()
            ];
        }

        return [
            "result" => "ok",
            "interface" => (string)$id,
            "ipaddr" => ($type === 'none') ? null : ($ip . "/" . $cidrInt),
        ];
    }
}
