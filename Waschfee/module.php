<?php

declare(strict_types=1);

class Waschfee extends IPSModule
{
    private const LIBRARY_GUID = '{91941F87-6EEC-4EFB-E6FF-6609959C1A56}';
    private const MODULE_GUID = '{63983619-7F4D-FD9F-0A5A-92B63CCAE733}';

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
        $this->DeleteProfiles();
    }

    public function Create()
    {
        // Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->RegisterTimer('Timer', 0, 'WF_UpdateTimer(' . $this->InstanceID . ');');
        $this->RegisterAttributeInteger('TimerStarted', 0);
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
        $this->StopTimer();
        $this->SetValue('Timer', false);
        $this->UpdateProgramsProfile();
        $this->SetOptions();
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $library = IPS_GetLibrary(self::LIBRARY_GUID);
        $module = IPS_GetModule(self::MODULE_GUID);
        $moduleInfo = [];
        $moduleInfo['name'] = $module['ModuleName'];
        $moduleInfo['version'] = $library['Version'] . '-' . $library['Build'];
        $moduleInfo['date'] = date('d.m.Y', $library['Date']);
        $moduleInfo['time'] = date('H:i', $library['Date']);
        $moduleInfo['developer'] = $library['Author'];
        $formData['elements'][0]['items'][1]['caption'] = "ID:\t\t\t\t" . $this->InstanceID;
        $formData['elements'][0]['items'][2]['caption'] = "Modul:\t\t\t" . $moduleInfo['name'];
        $formData['elements'][0]['items'][3]['caption'] = "Version:\t\t\t" . $moduleInfo['version'];
        $formData['elements'][0]['items'][4]['caption'] = "Datum:\t\t\t" . $moduleInfo['date'];
        $formData['elements'][0]['items'][5]['caption'] = "Uhrzeit:\t\t\t" . $moduleInfo['time'];
        $formData['elements'][0]['items'][6]['caption'] = "Entwickler:\t\t" . $moduleInfo['developer'];
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    public function CreatePrograms(int $DeviceType)
    {
        // Washing machine
        $preset = [
            ['Use' => true, 'Duration' => 30, 'Description' => 'Express'],
            ['Use' => true, 'Duration' => 54, 'Description' => 'Oberhemden 30°'],
            ['Use' => true, 'Duration' => 57, 'Description' => 'Oberhemden 40°'],
            ['Use' => true, 'Duration' => 75, 'Description' => 'Automatik 30°'],
            ['Use' => true, 'Duration' => 78, 'Description' => 'Automatik 40°'],
            ['Use' => true, 'Duration' => 109, 'Description' => 'Baumwolle 60°'],
            ['Use' => true, 'Duration' => 119, 'Description' => 'Baumwolle 50°']];
        // Tumble dryer
        if ($DeviceType == 1) {
            $preset = [
                ['Use' => true, 'Duration' => 8, 'Description' => 'Glätten'],
                ['Use' => true, 'Duration' => 43, 'Description' => 'Baumwolle'],
                ['Use' => true, 'Duration' => 118, 'Description' => 'Baumwolle schonen'],
                ['Use' => true, 'Duration' => 135, 'Description' => 'Automatik']];
        }
        IPS_SetProperty($this->InstanceID, 'Programs', json_encode($preset));
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
    }

    public function CreateAudioOutputScript(): void
    {
        $scriptID = IPS_CreateScript(0);
        IPS_SetName($scriptID, 'Audio-Benachrichtigung (#' . $this->InstanceID . ')');
        $scriptContent = "<?php\n\n// Bose Switchboard\n\$instanceID = 12345;\n\$productID = '4aa8fe15-d16c-23ba-e42b-86dc75a3ed09';\n\$audioURL = 'http://192.168.0.123:3777/user/ansage/waschmaschine.mp3';\n\$volume = 30;\nBSBS_PlayAudioNotification(\$instanceID, \$productID, \$audioURL, \$volume);";
        IPS_SetScriptContent($scriptID, $scriptContent);
        IPS_SetParent($scriptID, $this->InstanceID);
        IPS_SetPosition($scriptID, 100);
        IPS_SetHidden($scriptID, true);
    }

    public function UpdateTimer()
    {
        $remaining = time() - $this->ReadAttributeInteger('TimerStarted');
        if ($remaining >= $this->GetValue('Time') * 60) {
            $this->SetTimerInterval('Timer', 0);
            $this->SetValue('Timer', false);
            $this->SetValue('Remaining', 'Aus');
            $this->TriggerNotification();
            $this->TriggerAudioOutput();
        } else {
            $this->SetValue('Remaining', $this->StringifyTime($this->GetValue('Time') * 60 - $remaining));
            $this->SetTimerInterval('Timer', $this->ReadPropertyInteger('Interval') * 1000);
        }
    }

    ########## Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Programs':
                $this->SetValue($Ident, $Value);
                $this->SetValue('Time', $Value);
                $this->SetActive(true);
                break;

            case 'Timer':
                $this->SetActive($Value);
                break;

            case 'Time':
                $this->SetValue($Ident, $Value);
                $this->SetActive(false);
                break;

            case 'Notification':
            case 'AudioOutput':
                $this->SetValue($Ident, $Value);
                break;

        }
    }

    ########## Private

    public function CreateBackup(int $BackupCategory)
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == 102) {
            $name = 'Konfiguration (' . IPS_GetName($this->InstanceID) . ' #' . $this->InstanceID . ') ' . date('d.m.Y H:i:s');
            $config = IPS_GetConfiguration($this->InstanceID);
            // Create backup
            $content = "<?php\n// Backup " . date('d.m.Y, H:i:s') . "\n// " . $this->InstanceID . "\n$" . "config = '" . $config . "';";
            $backupScript = IPS_CreateScript(0);
            IPS_SetParent($backupScript, $BackupCategory);
            IPS_SetName($backupScript, $name);
            IPS_SetHidden($backupScript, true);
            IPS_SetScriptContent($backupScript, $content);
            echo 'Die Konfiguration wurde erfolgreich gesichert!';
        }
    }

    public function RestoreConfiguration(int $ConfigurationScript)
    {
        if ($ConfigurationScript != 0 && IPS_ObjectExists($ConfigurationScript)) {
            $object = IPS_GetObject($ConfigurationScript);
            if ($object['ObjectType'] == 3) {
                $content = IPS_GetScriptContent($ConfigurationScript);
                preg_match_all('/\'([^\']+)\'/', $content, $matches);
                $config = $matches[1][0];
                IPS_SetConfiguration($this->InstanceID, $config);
                if (IPS_HasChanges($this->InstanceID)) {
                    IPS_ApplyChanges($this->InstanceID);
                }
            }
            echo 'Die Konfiguration wurde erfolgreich wiederhergestellt!';
        }
    }

    private function RegisterProperties()
    {
        $this->RegisterPropertyString('Note', '');
        // Actuator
        $this->RegisterPropertyInteger('ActuatorState', 0);
        $this->RegisterPropertyInteger('ActuatorPower', 0);
        $this->RegisterPropertyInteger('ActuatorActualConsumption', 0);
        $this->RegisterPropertyInteger('ActuatorTotalConsumption', 0);
        // Options
        $this->RegisterPropertyBoolean('EnableActuatorState', true);
        $this->RegisterPropertyBoolean('EnablePrograms', true);
        $this->RegisterPropertyBoolean('EnableTimer', true);
        $this->RegisterPropertyBoolean('EnableTime', true);
        $this->RegisterPropertyBoolean('EnableRemaining', true);
        $this->RegisterPropertyBoolean('EnableNotification', true);
        $this->RegisterPropertyBoolean('EnableAudioOutput', true);
        $this->RegisterPropertyBoolean('EnableActuatorPower', true);
        $this->RegisterPropertyBoolean('EnableActuatorActualConsumption', true);
        $this->RegisterPropertyBoolean('EnableActuatorTotalConsumption', true);
        // Programs
        $this->RegisterPropertyString('Programs', '[]');
        // Timer
        $this->RegisterPropertyInteger('Interval', 30);
        // Notification
        $this->RegisterPropertyInteger('WebFront', 0);
        $this->RegisterPropertyString('Title', 'Waschfee');
        $this->RegisterPropertyString('Text', 'Die Wäsche ist fertig!');
        $this->RegisterPropertyString('Sound', '');
        // Audio notification
        $this->RegisterPropertyInteger('AudioOutputScript', 0);
    }

    private function CreateProfiles()
    {
        $profile = 'WF.' . $this->InstanceID . '.Programs';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, 'Menu');
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['Programs'];
        foreach ($profiles as $profile) {
            $profileName = 'WF.' . $this->InstanceID . '.' . $profile;
            if (@IPS_VariableProfileExists($profileName)) {
                IPS_DeleteVariableProfile($profileName);
            }
        }
    }

    private function UpdateProgramsProfile(): void
    {
        $profile = 'WF.' . $this->InstanceID . '.Programs';
        $associations = IPS_GetVariableProfile($profile)['Associations'];
        if (!empty($associations)) {
            foreach ($associations as $association) {
                // Delete
                IPS_SetVariableProfileAssociation($profile, $association['Value'], '', '', -1);
            }
        }
        $programs = json_decode($this->ReadPropertyString('Programs'));
        if (!empty($programs)) {
            foreach ($programs as $program) {
                // Create
                if ($program->Use) {
                    IPS_SetVariableProfileAssociation($profile, $program->Duration, $program->Description, '', -1);
                }
            }
        }
    }

    private function RegisterVariables()
    {
        // Programs
        $profile = 'WF.' . $this->InstanceID . '.Programs';
        $this->RegisterVariableInteger('Programs', 'Programme', $profile, 20);
        $this->EnableAction('Programs');
        // Timer
        $this->RegisterVariableBoolean('Timer', 'Timer', '~Switch', 30);
        $this->EnableAction('Timer');
        // Time
        $this->RegisterVariableInteger('Time', 'Zeit in Minuten', '', 40);
        IPS_SetIcon($this->GetIDForIdent('Time'), 'Clock');
        $this->EnableAction('Time');
        $this->SetValue('Time', 60);
        // Remaining
        $this->RegisterVariableString('Remaining', 'Verbleibend', '', 50);
        IPS_SetIcon($this->GetIDForIdent('Remaining'), 'Clock');
        // Notification
        $this->RegisterVariableBoolean('Notification', 'Benachrichtigung', '~Switch', 60);
        IPS_SetIcon($this->GetIDForIdent('Notification'), 'Mobile');
        $this->EnableAction('Notification');
        // Audio notification
        $this->RegisterVariableBoolean('AudioOutput', 'Audioausgabe', '~Switch', 70);
        IPS_SetIcon($this->GetIDForIdent('AudioOutput'), 'Speaker');
        $this->EnableAction('AudioOutput');
    }

    private function SetOptions(): void
    {
        // Actuator power
        $targetID = $this->ReadPropertyInteger('ActuatorState');
        $linkID = $this->GetLink('Power', $targetID);
        if ($targetID != 0 && @IPS_ObjectExists($targetID)) {
            if ($linkID == 0) {
                $linkID = IPS_CreateLink();
            }
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetPosition($linkID, 10);
            IPS_SetName($linkID, 'Power');
            IPS_SetInfo($linkID, 'Power');
            IPS_SetIcon($linkID, 'Power');
            IPS_SetLinkTargetID($linkID, $targetID);
        } else {
            if ($linkID != 0) {
                IPS_SetHidden($linkID, !$this->ReadPropertyBoolean('EnableActuatorState'));
            }
        }
        // Programs
        IPS_SetHidden($this->GetIDForIdent('Programs'), !$this->ReadPropertyBoolean('EnablePrograms'));
        // Timer
        IPS_SetHidden($this->GetIDForIdent('Timer'), !$this->ReadPropertyBoolean('EnableTimer'));
        IPS_SetHidden($this->GetIDForIdent('Time'), !$this->ReadPropertyBoolean('EnableTime'));
        IPS_SetHidden($this->GetIDForIdent('Remaining'), !$this->ReadPropertyBoolean('EnableRemaining'));
        // Notification
        IPS_SetHidden($this->GetIDForIdent('Notification'), !$this->ReadPropertyBoolean('EnableNotification'));
        // Audio notification
        IPS_SetHidden($this->GetIDForIdent('AudioOutput'), !$this->ReadPropertyBoolean('EnableAudioOutput'));
        // Actuator power
        $targetID = $this->ReadPropertyInteger('ActuatorPower');
        $linkID = $this->GetLink('Aktuelle Leistung', $targetID);
        if ($targetID != 0 && @IPS_ObjectExists($targetID)) {
            if ($linkID == 0) {
                $linkID = IPS_CreateLink();
            }
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetPosition($linkID, 80);
            IPS_SetName($linkID, 'Aktuelle Leistung');
            IPS_SetInfo($linkID, 'Aktuelle Leistung');
            IPS_SetIcon($linkID, 'Electricity');
            IPS_SetLinkTargetID($linkID, $targetID);
        } else {
            if ($linkID != 0) {
                IPS_SetHidden($linkID, !$this->ReadPropertyBoolean('EnableActuatorPower'));
            }
        }
        // Current consumption
        $targetID = $this->ReadPropertyInteger('ActuatorActualConsumption');
        $linkID = $this->GetLink('Aktueller Verbrauch', $targetID);
        if ($targetID != 0 && @IPS_ObjectExists($targetID)) {
            if ($linkID == 0) {
                $linkID = IPS_CreateLink();
            }
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetPosition($linkID, 90);
            IPS_SetName($linkID, 'Aktueller Verbrauch');
            IPS_SetInfo($linkID, 'Aktueller Verbrauch');
            IPS_SetIcon($linkID, 'Electricity');
            IPS_SetLinkTargetID($linkID, $targetID);
        } else {
            if ($linkID != 0) {
                IPS_SetHidden($linkID, !$this->ReadPropertyBoolean('EnableActuatorActualConsumption'));
            }
        }
        // Total consumption
        $targetID = $this->ReadPropertyInteger('ActuatorTotalConsumption');
        $linkID = $this->GetLink('Gesamtverbrauch', $targetID);
        if ($targetID != 0 && @IPS_ObjectExists($targetID)) {
            if ($linkID == 0) {
                $linkID = IPS_CreateLink();
            }
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetPosition($linkID, 100);
            IPS_SetName($linkID, 'Gesamtverbrauch');
            IPS_SetInfo($linkID, 'Gesamtverbrauch');
            IPS_SetIcon($linkID, 'EnergyProduction');
            IPS_SetLinkTargetID($linkID, $targetID);
        } else {
            if ($linkID != 0) {
                IPS_SetHidden($linkID, true);
                IPS_SetHidden($linkID, !$this->ReadPropertyBoolean('EnableActuatorTotalConsumption'));
            }
        }
    }

    private function GetLink(string $LinkInfo, int $TargetID)
    {
        $linkID = 0;
        $children = IPS_GetChildrenIDs($this->InstanceID);
        if (!empty($children)) {
            foreach ($children as $child) {
                $type = IPS_GetObject($child)['ObjectType'];
                if ($type === 6) {
                    $info = IPS_GetObject($child)['ObjectInfo'];
                    if ($info == $LinkInfo) {
                        $target = IPS_GetLink($child)['TargetID'];
                        if ($target == $TargetID) {
                            $linkID = $child;
                        }
                    }
                }
            }
        }
        return $linkID;
    }

    private function SetActive($active)
    {
        if ($active) {
            $this->StartTimer();
        } else {
            $this->StopTimer();
        }
        $this->SetValue('Timer', $active);
    }

    private function StartTimer()
    {
        $this->WriteAttributeInteger('TimerStarted', time());
        $this->SetTimerInterval('Timer', $this->ReadPropertyInteger('Interval') * 1000);
        $this->SetValue('Remaining', $this->StringifyTime(intval($this->GetValue('Time')) * 60));
        $this->SendDebug(__FUNCTION__, 'Timer-Info: aktiv', 0);
    }

    private function StopTimer()
    {
        $this->SetTimerInterval('Timer', 0);
        $this->SetValue('Remaining', 'Aus');
    }

    private function StringifyTime(int $seconds)
    {
        return sprintf('%02d:%02d:%02d', ($seconds / (60 * 60)), ($seconds / 60 % 60), $seconds % 60);
    }

    private function TriggerNotification()
    {
        if (!$this->GetValue('Notification')) {
            return;
        }
        $id = $this->ReadPropertyInteger('WebFront');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $title = substr($this->ReadPropertyString('Title'), 0, 32);
            $text = $this->ReadPropertyString('Text');
            $sound = $this->ReadPropertyString('Sound');
            @WFC_PushNotification($id, $title, $text, $sound, 0);
        }
    }

    private function TriggerAudioOutput()
    {
        if (!$this->GetValue('AudioOutput')) {
            return;
        }
        $id = $this->ReadPropertyInteger('AudioOutputScript');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            @IPS_RunScript($id);
        }
    }
}