<?php

class HeidelbergAbfall extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString("FileName", "Abfallkalender2026.ics");
        $this->RegisterPropertyInteger("IntervalDays", 1);
        $this->RegisterPropertyBoolean("Active", false);

        // Variablen
        $this->RegisterVariableString("Activated", "Modulstatus", "", 1);
        $this->RegisterVariableString("Restmuell", "Restmüll", "", 2);
        $this->RegisterVariableString("Biomuell", "Biomüll", "", 3);
        $this->RegisterVariableString("Papier", "Papier", "", 4);
        $this->RegisterVariableString("GelbeTonne", "Gelbe Tonne", "", 5);

        // Icons für Kachel-Visualisierung
        IPS_SetIcon($this->GetIDForIdent("Restmuell"), "trash-can");
        IPS_SetIcon($this->GetIDForIdent("Biomuell"), "leaf");
        IPS_SetIcon($this->GetIDForIdent("Papier"), "file");
        IPS_SetIcon($this->GetIDForIdent("GelbeTonne"), "recycle");

        // Timer korrekt registrieren
        $this->RegisterTimer("UpdateTimer", 0, "IPS_RequestAction(\$_IPS['TARGET'], 'RunUpdate', 0);");
        $this->RegisterTimer("ReminderTimer", 0, "IPS_RequestAction(\$_IPS['TARGET'], 'RunReminder', 0);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Aktivierung
        $active = $this->ReadPropertyBoolean("Active");
        $days   = $this->ReadPropertyInteger("IntervalDays");

        if ($active) {
            $intervalMs = $days * 24 * 60 * 60 * 1000;
            $this->SetTimerInterval("UpdateTimer", $intervalMs);
            $this->ScheduleReminder();
            SetValueString($this->GetIDForIdent("Activated"), "Aktiv");
        } else {
            $this->SetTimerInterval("UpdateTimer", 0);
            $this->SetTimerInterval("ReminderTimer", 0);
            SetValueString($this->GetIDForIdent("Activated"), "Inaktiv");
        }

        // Reminder neu planen
        $this->ScheduleReminder();
    }

    // RequestAction für Timer
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "RunUpdate":
                $this->Update();
                break;

            case "RunReminder":
                $this->DoReminder();
                break;
        }
    }

    // ICS verarbeiten
    public function Update()
    {
        $fileName = $this->ReadPropertyString("FileName");
        $local = "/var/lib/symcon/modules/MyAbfallkalender/Abfallkalender/" . $fileName;

        if (!file_exists($local)) {
            IPS_LogMessage("HeidelbergAbfall", "ICS-Datei nicht gefunden: $local");
            return;
        }

        $events = $this->ParseICS($local);
        if (!$events) {
            IPS_LogMessage("HeidelbergAbfall", "Keine Events gefunden.");
            return;
        }

        $this->UpdateNextEvents($events);
    }

    private function ParseICS($file)
    {
        $content = file_get_contents($file);
        if ($content === false) return false;

        $lines = explode("\n", $content);
        $events = [];
        $current = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === "BEGIN:VEVENT") $current = [];

            if (strpos($line, "DTSTART") === 0)
                $current["date"] = substr($line, strpos($line, ":") + 1);

            if (strpos($line, "SUMMARY:") === 0)
                $current["type"] = substr($line, 8);

            if ($line === "END:VEVENT" && isset($current["date"]) && isset($current["type"]))
                $events[] = $current;
        }

        return $events;
    }

    private function UpdateNextEvents($events)
    {
        $today = date("Ymd");

        $next = [
            "Restmüll"    => null,
            "Biomüll"     => null,
            "Papier"      => null,
            "Gelbe Tonne" => null
        ];

        foreach ($events as $e) {
            if ($e["date"] < $today) continue;

            $type = strtolower($e["type"]);

            if (strpos($type, "rest") !== false && !$next["Restmüll"])
                $next["Restmüll"] = $e["date"];

            if (strpos($type, "bio") !== false && !$next["Biomüll"])
                $next["Biomüll"] = $e["date"];

            if (strpos($type, "papier") !== false && !$next["Papier"])
                $next["Papier"] = $e["date"];

            if (strpos($type, "gelb") !== false && !$next["Gelbe Tonne"])
                $next["Gelbe Tonne"] = $e["date"];
        }

        $ids = [
            'Restmuell' => $this->GetIDForIdent("Restmuell"),
            'Biomuell'  => $this->GetIDForIdent("Biomuell"),
            'Papier'    => $this->GetIDForIdent("Papier"),
            'GelbeTonne'=> $this->GetIDForIdent("GelbeTonne")
        ];

        $values = [
            'Restmuell' => $next["Restmüll"] ? $this->FormatDate($next["Restmüll"]) : "",
            'Biomuell'  => $next["Biomüll"] ? $this->FormatDate($next["Biomüll"]) : "",
            'Papier'    => $next["Papier"] ? $this->FormatDate($next["Papier"]) : "",
            'GelbeTonne'=> $next["Gelbe Tonne"] ? $this->FormatDate($next["Gelbe Tonne"]) : ""
        ];

        foreach ($ids as $ident => $id) {
            if ($id === 0) continue;
            SetValueString($id, $values[$ident]);
            IPS_SetHidden($id, $values[$ident] === "");
        }
    }

    private function FormatDate($icsDate)
    {
        $ts = strtotime($icsDate);
        $tage = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
        return $tage[date("w", $ts)] . " " . date("j.n.Y", $ts);
    }

    // Reminder-Timer jeden Tag neu planen
    private function ScheduleReminder()
    {
        $target = strtotime("17:00");
        $now = time();
        $interval = $target - $now;

        if ($interval < 0) {
            $interval += 24 * 60 * 60;
        }

        $this->SetTimerInterval("ReminderTimer", $interval * 1000);
    }

    // Popup am Vortag um 17:00
    private function DoReminder()
    {
        // Modul deaktiviert? → Keine Erinnerung ausführen 
        if (!$this->ReadPropertyBoolean("Active")) 
            { return; 
        }
            
        // Reminder nach Ausführung neu planen
        $this->ScheduleReminder();

        $tomorrow = $this->FormatDate(date("Ymd", strtotime("+1 day")));

        $rest = GetValueString($this->GetIDForIdent("Restmuell"));
        $bio = GetValueString($this->GetIDForIdent("Biomuell"));
        $papier = GetValueString($this->GetIDForIdent("Papier"));
        $gelb = GetValueString($this->GetIDForIdent("GelbeTonne"));

        $isTomorrowTrash =
            strpos($rest, $tomorrow) !== false ||
            strpos($bio, $tomorrow) !== false ||
            strpos($papier, $tomorrow) !== false ||
            strpos($gelb, $tomorrow) !== false;

        if ($isTomorrowTrash) {
            VISU_PostNotification(
                27558,
                "Müll-Erinnerung",
                "Bitte den Müll rausstellen!",
                "Info",
                28352
            );
        }
    }
}
