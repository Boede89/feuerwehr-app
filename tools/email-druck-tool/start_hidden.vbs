' Startet das E-Mail Druck Tool ohne sichtbares Fenster (für Autostart)
Set WshShell = CreateObject("WScript.Shell")
Set FSO = CreateObject("Scripting.FileSystemObject")
scriptDir = FSO.GetParentFolderName(WScript.ScriptFullName)
WshShell.CurrentDirectory = scriptDir
WshShell.Run "pythonw """ & scriptDir & "\email_druck_tool.py"" --headless", 0, False
