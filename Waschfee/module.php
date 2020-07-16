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
        $this->SetValue('Active', false);
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
            $this->SetValue('Active', false);
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
            case 'Active':
                $this->SetActive($Value);
                break;

            case 'Time':
                $this->SetValue($Ident, $Value);
                $this->SetActive(false);
                break;

            case 'Programs':
                $this->SetValue($Ident, $Value);
                $this->SetValue('Time', $Value);
                $this->SetActive(true);
                break;

            case 'Notification':
            case 'AudioOutput':
                $this->SetValue($Ident, $Value);
                break;

        }
    }

    ########## Private

    private function RegisterProperties()
    {
        $this->RegisterPropertyString('Note', '');
        $this->RegisterPropertyBoolean('EnablePrograms', true);
        $this->RegisterPropertyBoolean('EnableTime', true);
        $this->RegisterPropertyBoolean('EnableRemaining', true);
        $this->RegisterPropertyBoolean('EnableNotification', true);
        $this->RegisterPropertyBoolean('EnableAudioOutput', true);
        $this->RegisterPropertyString('Programs', '[]');
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyInteger('WebFront', 0);
        $this->RegisterPropertyString('Title', 'Waschfee');
        $this->RegisterPropertyString('Text', 'Die Wäsche ist fertig!');
        $this->RegisterPropertyString('Sound', '');
        $this->RegisterPropertyInteger('AudioOutputScript', 0);
    }

    private function CreateProfiles()
    {
        // Programs
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
        $this->RegisterVariableBoolean('Active', 'Aktiv', '~Switch', 10);
        $this->EnableAction('Active');

        $profile = 'WF.' . $this->InstanceID . '.Programs';
        $this->RegisterVariableInteger('Programs', 'Programme', $profile, 20);
        $this->EnableAction('Programs');

        $this->RegisterVariableInteger('Time', 'Zeit in Minuten', '', 30);
        IPS_SetIcon($this->GetIDForIdent('Time'), 'Clock');
        $this->EnableAction('Time');
        $this->SetValue('Time', 60);

        $this->RegisterVariableString('Remaining', 'Verbleibend', '', 40);
        IPS_SetIcon($this->GetIDForIdent('Remaining'), 'Clock');

        $this->RegisterVariableBoolean('Notification', 'Benachrichtigung', '~Switch', 50);
        IPS_SetIcon($this->GetIDForIdent('Notification'), 'Mobile');
        $this->EnableAction('Notification');

        $this->RegisterVariableBoolean('AudioOutput', 'Audioausgabe', '~Switch', 60);
        IPS_SetIcon($this->GetIDForIdent('AudioOutput'), 'Speaker');
        $this->EnableAction('AudioOutput');
    }

    private function SetOptions(): void
    {
        IPS_SetHidden($this->GetIDForIdent('Programs'), !$this->ReadPropertyBoolean('EnablePrograms'));
        IPS_SetHidden($this->GetIDForIdent('Time'), !$this->ReadPropertyBoolean('EnableTime'));
        IPS_SetHidden($this->GetIDForIdent('Remaining'), !$this->ReadPropertyBoolean('EnableRemaining'));
        IPS_SetHidden($this->GetIDForIdent('Notification'), !$this->ReadPropertyBoolean('EnableNotification'));
        IPS_SetHidden($this->GetIDForIdent('AudioOutput'), !$this->ReadPropertyBoolean('EnableAudioOutput'));
    }

    private function SetActive($active)
    {
        if ($active) {
            $this->StartTimer();
        } else {
            $this->StopTimer();
        }
        $this->SetValue('Active', $active);
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
