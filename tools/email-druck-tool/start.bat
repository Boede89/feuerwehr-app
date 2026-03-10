@echo off
cd /d "%~dp0"
REM Überwachung im Hintergrund starten (falls noch nicht läuft)
start /b pythonw email_druck_tool.py --headless 2>nul
REM GUI öffnen (Einstellungen)
pythonw email_druck_tool.py 2>nul
if errorlevel 1 python email_druck_tool.py
pause
