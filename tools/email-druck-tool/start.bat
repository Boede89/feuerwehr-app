@echo off
cd /d "%~dp0"
REM GUI starten (Fenster sichtbar)
pythonw email_druck_tool.py 2>nul
if errorlevel 1 python email_druck_tool.py
pause
