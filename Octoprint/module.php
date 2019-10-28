<?php

class Octoprint extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("URL", "");
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyInteger("UpdateInterval", 1);

        $this->RegisterTimer("Update", $this->ReadPropertyInteger("UpdateInterval"), 'OCTO_UpdateData($_IPS[\'TARGET\']);');

        $this->CreateVarProfile("OCTO.Size", 2, " MB", 0, 9999, 0, 1, "Database");
        $this->CreateVarProfile("OCTO.Completion", 2, " %", 0, 100, 1, 0, "Hourglass");
    }

    public function Destroy() {
        parent::Destroy();
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        if ($this->ReadPropertyString("URL") != "") {
            $this->SetTimerInterval("Update", $this->ReadPropertyInteger("UpdateInterval") * 1000 * 60);
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

    private function RequestAPI($path) {
        $url = $this->ReadPropertyString("URL");
        $apiKey = $this->ReadPropertyString("APIKey");

        $this->SendDebug("OCTO Requested URL", $url, 0);
        $headers = array(
            'X-Api-Key: ' . $apiKey
        );
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
}