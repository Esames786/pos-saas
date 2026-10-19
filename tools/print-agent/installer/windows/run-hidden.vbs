' Bingoo Print Agent - bina kisi window ke chalata hai.
'
' AGENT-AUTOSTART-HIDDEN-1 (16 Oct 2026)
'
' Masla jo is file ne hal kiya: agent ek console program hai, is liye jab
' scheduled task use seedha chalata tha to screen par ek kaali window khul
' jati thi. Client use "nodejs ki window" samajh kar band kar deta tha, aur
' us ke saath agent bhi band ho jata tha - parchiyan queue me khari reh
' jatin aur kisi ko sabab nazar nahi aata tha.
'
' WScript.Shell.Run ka doosra parameter 0 hai = window bilkul na dikhao.
' Teesra False hai = is script ko agent ke khatm hone ka intezar na karna
' hai; wo chalta rahe aur ye script foran nikal jaye.
'
' Ye file agent ki EXE ke SAATH hi rehni chahiye: raasta usi folder se
' banaya jata hai jahan ye khud rakhi hai, kisi likhe hue raaste se nahi -
' taake install folder badalne par bhi theek rahe.

Option Explicit

Dim sh, fso, folder, exe

Set sh  = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

folder = fso.GetParentFolderName(WScript.ScriptFullName)
exe    = fso.BuildPath(folder, "BingooPrintAgent.exe")

If Not fso.FileExists(exe) Then
    ' Khamoshi se mat maro. Scheduled task ka natija code dekha ja sakta hai
    ' (Get-ScheduledTaskInfo -> LastTaskResult), aur 2 batata hai ke exe hi
    ' nahi mili - ye baat ghalat printer ya kharab config se bilkul alag hai.
    WScript.Quit 2
End If

sh.Run """" & exe & """ run", 0, False
