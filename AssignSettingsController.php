<?php

namespace OPNsense\Interfaces\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Config;

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
}
