' Bingoo Print Agent - bina kisi window ke chalata hai, aur gir jaye to dobara.
'
' AGENT-AUTOSTART-HIDDEN-1 (16 Oct 2026)
'
' Masla jo is file ne hal kiya: agent ek console program hai, is liye jab
' use seedha chalaya jata tha to screen par ek kaali window khul jati thi.
' Client use "nodejs ki window" samajh kar band kar deta tha, aur us ke saath
' agent bhi band ho jata tha - parchiyan queue me khari reh jatin aur kisi ko
' sabab nazar nahi aata tha.
'
' WScript.Shell.Run ka doosra parameter 0 hai = window bilkul na dikhao.
'
' AGENT-UPGRADE-1 (2.7.0, 11 Nov) - ab ye CHOWKIDAR bhi hai.
'
' Owner: "thermal install ke baad tum script se haath se agent on karwa rahe
' the" - agent khud nahi chalta tha. 2.7.0 me ye file Windows ke logon par
' (HKCU Run, admin ke baghair) chalti hai aur agent ke saath ZINDA rehti hai:
'
'   - Teesra parameter True = agent ke khatm hone ka intezar.
'   - Agent GIR jaye (exit code 0 ke ilawa) to 5 second baad dobara.
'   - Agent khud saaf band ho (exit 0 - maslan "doosra agent pehle se chal
'     raha hai") to ye bhi ruk jati hai: do agent kabhi nahi.
'   - Exit 4 = PC abhi pair hi nahi hua - har 5 second dobara uthane ka koi
'     faida nahi; ruk jati hai (wajah agent.log me).
'   - `stop.flag` mojood ho to ruk jati hai - installer upgrade / uninstall se
'     pehle yehi rakhta hai, taake purana exe badalte waqt dobara na uth jaye.
'
' Ye file agent ki EXE ke SAATH hi rehni chahiye: raasta usi folder se
' banaya jata hai jahan ye khud rakhi hai, kisi likhe hue raaste se nahi -
' taake install folder badalne par bhi theek rahe.

Option Explicit

Dim sh, fso, folder, exe, flag, rc

Set sh  = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

folder = fso.GetParentFolderName(WScript.ScriptFullName)
exe    = fso.BuildPath(folder, "BingooPrintAgent.exe")
flag   = sh.ExpandEnvironmentStrings("%LOCALAPPDATA%") & "\BingooPrintAgent\stop.flag"

If Not fso.FileExists(exe) Then
    ' Khamoshi se mat maro: 2 batata hai ke exe hi nahi mili - ye baat ghalat
    ' printer ya kharab config se bilkul alag hai.
    WScript.Quit 2
End If

Do
    If fso.FileExists(flag) Then Exit Do
    rc = sh.Run("""" & exe & """ run", 0, True)
    If rc = 0 Or rc = 4 Then Exit Do
    If fso.FileExists(flag) Then Exit Do
    WScript.Sleep 5000
Loop
