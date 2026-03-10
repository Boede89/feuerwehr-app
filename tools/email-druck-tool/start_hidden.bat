@echo off
cd /d "%~dp0"
REM Im Hintergrund ohne Fenster – für Autostart
pythonw email_druck_tool.py --headless 2>nul
if errorlevel 1 python email_druck_tool.py --headless
