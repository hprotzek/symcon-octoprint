<?php

require_once __DIR__ . '/../libs/Ping.php';

class Octoprint extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("Scheme", "http");
        $this->RegisterPropertyString("Host", "");
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyInteger("UpdateInterval", 1);
        $this->RegisterPropertyBoolean("CamEnabled", false);
        $this->RegisterPropertyBoolean("EnclosureNeopixel", false);

        $this->RegisterTimer("Update", $this->ReadPropertyInteger("UpdateInterval"), 'OCTO_UpdateData($_IPS[\'TARGET\']);');
        $this->RegisterScript("NeopixelsOn", "Neopixels On", "<?php\n\nOCTO_LightsOn(" . $this->InstanceID . ");", 0);
        $this->RegisterScript("NeopixelsOff", "Neopixels Off", "<?php\n\nOCTO_LightsOff(" . $this->InstanceID . ");", 0);

        $this->CreateVarProfile("OCTO.Size", 2, " MB", 0, 9999, 0, 1, "Database");
        $this->CreateVarProfile("OCTO.Completion", 2, " %", 0, 100, 1, 0, "Hourglass");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        if ($this->ReadPropertyString("Host") != "") {
            $this->SetTimerInterval("Update", $this->ReadPropertyInteger("UpdateInterval") * 1000 * 60);

            if ($this->ReadPropertyBoolean("CamEnabled")) {
                $url = $this->ReadPropertyString("Scheme") . '://' . $this->ReadPropertyString("Host");
                $streamUrl = $url . '/webcam/?action=stream';
                $media = @IPS_GetMediaIDByName("Cam Stream", $this->InstanceID);
                if (!$media) {
                    $media = IPS_CreateMedia(3);
                    IPS_SetIdent($media, "CamStream");
                    IPS_SetName($media, "Cam Stream");
                    IPS_SetMediaFile($media, $streamUrl, true);
                    IPS_SetParent($media, $this->InstanceID);
                } else {
                    if (md5(IPS_GetMedia($media)['MediaFile']) != md5($streamUrl)) {
                        IPS_SetMediaFile($media, $streamUrl, true);
                    }
                }
            }

            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

        $this->MaintainVariable("Status", "Status", 3, "TextBox", 0, true);

        $this->MaintainVariable("BedTempActual", "Bed Temperature Actual", 2, "Temperature", 0, true);
        $this->MaintainVariable("BedTempTarget", "Bed Temperature Target", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempActual", "Nozzle Temperature Actual", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempTarget", "Nozzle Temperature Target", 2, "Temperature", 0, true);
        $this->MaintainVariable("ToolTempTarget", "File Size", 2, "Temperature", 0, true);

        $this->MaintainVariable("FileSize", "File Size", 2, "OCTO.Size", 0, true);
        $this->MaintainVariable("FileName", "File Name", 3, "TextBox", 0, true);
        $this->MaintainVariable("PrintTime", "Print Time", 3, "TextBox", 0, true);
        $this->MaintainVariable("PrintTimeLeft", "Print Time Left", 3, "TextBox", 0, true);
        $this->MaintainVariable("ProgressCompletion", "Progress Completion", 2, "OCTO.Completion", 0, true);
        $this->MaintainVariable("PrintFinished", "Print Finished", 3, "TextBox", 0, true);
    }

    public function UpdateData() {
        $ping = new Ping($this->ReadPropertyString("Host"));
        if ($ping->ping() == false) {
            SetValue($this->GetIDForIdent("Status"), "Offline");
            SetValue($this->GetIDForIdent("ProgressCompletion"), 0);
            $this->SendDebug(__FUNCTION__, 'Octoprint is offline', 0);
            return;
        }

        $data = $this->RequestAPI('/api/connection');
        SetValue($this->GetIDForIdent("Status"), $data->current->state);

        $data = $this->RequestAPI('/api/printer');
        SetValue($this->GetIDForIdent("BedTempActual"), $this->FixupInvalidValue($data->temperature->bed->actual));
        SetValue($this->GetIDForIdent("BedTempTarget"), $this->FixupInvalidValue($data->temperature->bed->target));
        SetValue($this->GetIDForIdent("ToolTempActual"), $this->FixupInvalidValue($data->temperature->tool0->actual));
        SetValue($this->GetIDForIdent("ToolTempTarget"), $this->FixupInvalidValue($data->temperature->tool0->target));

        $data = $this->RequestAPI('/api/job');
        SetValue($this->GetIDForIdent("FileSize"), $this->FixupInvalidValue($data->job->file->size) / 1000000);
        SetValue($this->GetIDForIdent("FileName"), $data->job->file->name);
        SetValue($this->GetIDForIdent("PrintTime"), $this->CreateDuration($data->progress->printTime));
        SetValue($this->GetIDForIdent("PrintTimeLeft"), $this->CreateDuration($data->progress->printTimeLeft));
        SetValue($this->GetIDForIdent("ProgressCompletion"), $this->FixupInvalidValue($data->progress->completion));
        SetValue($this->GetIDForIdent("PrintFinished"), $this->CreatePrintFinished($data->progress->printTimeLeft));
    }

    public function LightsOff() {
        if ($this->ReadPropertyBoolean("EnclosureNeopixel")) {
            $this->RequestAPI("/plugin/enclosure/setNeopixel?index_id=2&red=0&green=0&blue=0");
        }
    }

    public function LightsOn() {
        if ($this->ReadPropertyBoolean("EnclosureNeopixel")) {
            $this->RequestAPI("/plugin/enclosure/setNeopixel?index_id=2&red=255&green=255&blue=255");
        }
    }

    private function RequestAPI($path) {
        $url = $this->ReadPropertyString("Scheme") . '://' . $this->ReadPropertyString("Host");
        $apiKey = $this->ReadPropertyString("APIKey");

        $headers = array(
            'X-Api-Key:' . $apiKey
        );
        $this->SendDebug("OCTO Requested URL", $url . $path, 0);
        $ch = curl_init($url . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);

        $content = json_decode($response);
        $this->SendDebug("OCTO Response", print_r($content, true), 0);
        if (isset($content->response->error)) {
            throw new Exception("Response from Octoprint is invalid: " . $content->response->error->description);
        }
        return $content;
    }

    private function FixupInvalidValue($Value) {
        if (is_numeric($Value)) {
            return floatval($Value);
        } else {
            return 0;
        }
    }

    private function CreateVarProfile($name, $ProfileType, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Icon) {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $ProfileType);
            IPS_SetVariableProfileText($name, "", $Suffix);
            IPS_SetVariableProfileValues($name, $MinValue, $MaxValue, $StepSize);
            IPS_SetVariableProfileDigits($name, $Digits);
            IPS_SetVariableProfileIcon($name, $Icon);
        }
    }

    private function CreateDuration($Value) {
        return gmdate("H:i:s", $this->FixupInvalidValue($Value));
    }

    private function CreatePrintFinished($Value) {
        if (is_numeric($Value)) {
            $timestamp = time();
            $time = $timestamp + $Value;
            return date('l G:i', $time) . ' Uhr';
        } else {
            return "Calculating ...";
        }
    }

    private function httpGet($url) {
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_TIMEOUT, 1000);
        $data = curl_exec($handle);
        curl_close($handle);
    }
}
