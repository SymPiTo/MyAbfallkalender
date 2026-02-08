<?php

class HeidelbergAbfall extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Pfad zur ICS-Datei auf dem Windows-Rechner
        $this->RegisterPropertyString("RemoteFile", "\\\\WINPC\\Freigabe\\abfall.ics");

        // Lokaler Speicherort in Symcon
        $this->RegisterPropertyString("LocalFile", IPS_GetKernelDir() . "abfall.ics");

        // Variablen
        $this->RegisterVariableString("Restmuell", "Restmüll");
        $this->RegisterVariableString("Biomuell", "Biomüll");
        $this->RegisterVariableString("Papier", "Papier");
        $this->RegisterVariableString("GelbeTonne", "Gelbe Tonne");

        // Tägliches Update
        $this->RegisterTimer("UpdateTimer", 24 * 60 * 60 * 1000, "HDABF_Update(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function Update()
    {
        $remote = $this->ReadPropertyString("RemoteFile");
        $local  = $this->ReadPropertyString("LocalFile");

        if (!file_exists($remote)) {
            IPS_LogMessage("HeidelbergAbfall", "Remote ICS file not found: $remote");
            return;
        }

        // Datei kopieren
        copy($remote, $local);

        // ICS parsen
        $events = $this->ParseICS($local);

        // Nächste Termine bestimmen
        $this->UpdateNextEvents($events);
    }

    private function ParseICS($file)
    {
        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $events = [];
        $current = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === "BEGIN:VEVENT") {
                $current = [];
            }

            if (strpos($line, "DTSTART") === 0) {
                $date = substr($line, strpos($line, ":") + 1);
                $current["date"] = $date;
            }

            if (strpos($line, "SUMMARY:") === 0) {
                $summary = substr($line, 8);
                $current["type"] = $summary;
            }

            if ($line === "END:VEVENT") {
                $events[] = $current;
            }
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

            if (strpos($type, "rest") !== false && !$next["Restmüll"]) {
                $next["Restmüll"] = $e["date"];
            }
            if (strpos($type, "bio") !== false && !$next["Biomüll"]) {
                $next["Biomüll"] = $e["date"];
            }
            if (strpos($type, "papier") !== false && !$next["Papier"]) {
                $next["Papier"] = $e["date"];
            }
            if (strpos($type, "gelb") !== false && !$next["Gelbe Tonne"]) {
                $next["Gelbe Tonne"] = $e["date"];
            }
        }

        // Variablen schreiben
        SetValueString($this->GetIDForIdent("Restmuell"), $next["Restmüll"]);
        SetValueString($this->GetIDForIdent("Biomuell"), $next["Biomüll"]);
        SetValueString($this->GetIDForIdent("Papier"), $next["Papier"]);
        SetValueString($this->GetIDForIdent("GelbeTonne"), $next["Gelbe Tonne"]);
    }
}
