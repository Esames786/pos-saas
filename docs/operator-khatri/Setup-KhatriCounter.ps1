# Bingoo Edge - Khatri Biryani: ONE-FILE counter setup (run on every cashier PC, and on the owner's laptop).
# What it does (idempotent, safe to re-run):
#   1. trusts the Khatri branch CA (public certificate embedded below) in the machine's Trusted Root store
#   2. adds the hosts line  192.168.100.52  bingoo-edge.local
#   3. creates three desktop shortcuts for ALL users (Public Desktop): BINGOO Online / BINGOO Offline / Branch Server Status
#   4. checks https://bingoo-edge.local:8443 by name and by IP with FULL certificate validation (never bypassed)
# It relaunches itself elevated (UAC prompt -> Yes). Nothing else is changed; no password, no secret inside.
# Usage: right-click -> Run with PowerShell   (or:  powershell -ExecutionPolicy Bypass -File .\Setup-KhatriCounter.ps1)

$ErrorActionPreference = 'Stop'
$EdgeIp   = '192.168.100.52'
$EdgeHost = 'bingoo-edge.local'
$Port     = 8443
$CaThumb  = '125DFE0A8EF21BDB6CA844EF987856E29E0D5EE4'
$CaPem = @'
-----BEGIN CERTIFICATE-----
MIIFWzCCA0OgAwIBAgIQbu/MmHP7vIVH2YwMH0sCETANBgkqhkiG9w0BAQsFADBG
MRgwFgYDVQQKDA9CaW5nb28gUE9TIEVkZ2UxKjAoBgNVBAMMIUJpbmdvbyBFZGdl
IEJyYW5jaCBDQSAoS2hhdHJpLTAxKTAeFw0yNjExMjEwNjQzMDNaFw0zMTExMjEw
NjUzMDJaMEYxGDAWBgNVBAoMD0JpbmdvbyBQT1MgRWRnZTEqMCgGA1UEAwwhQmlu
Z29vIEVkZ2UgQnJhbmNoIENBIChLaGF0cmktMDEpMIICIjANBgkqhkiG9w0BAQEF
AAOCAg8AMIICCgKCAgEAwSJl+pcaoJmwPcYoKz9PuTg2wh4JcB1/uJL79GO9r7jM
4KUJVewgVvgv7UQj30iUZJJF0qzmFYPioYtD76jeJ02/bClBI4HmuJ1qAIvMhGik
yq65tK/rCXu4exZf6uaV+2tmwh9KuuwUhjcHpiX70iR53AQ0fpMig4NymqS2JQU+
ySRanKEoJkw01xy7Kl+xmCJqfCfEDA+GI9lzDCiAi0PVYQLIV7TuOEHszZfYY95f
dG3UxoV9DF/vSFi3mA4tdEoPqOFC3Yq27kyXFowm5TEIECm/pyfsrmZMn04Zv0yD
DJzXyKmL9KYFqZspHva6qXi0NJ4bmTe//Zbe+v3HZ9Eaxu6AmMu4XEqUImBQLi4A
Q+hMS/iGxDUOW4+8popHk961l19fMy7Bwt67ADgIngGuETSYrcZqM7txbR8C0Z2C
1LglXWtabguehP0mF1SDpoAY1TWBdDsFdWpAQN86+zb+kJwIxp1z1eS948kzN7im
O/WsJcHfvTwdpu0WdB26yljGrg+Y0ckE3z1fHZHjdYV0i0G2RjhC39KbJQVCJd/Y
3pf/1vFHo7oxpUan+ll5ndJ0Tm9UdSoA5BocWZmn6E9I99EIe9SGxm8VJApI4vaf
SganjvQ2ZaskK1quDjfhqK7CLsvWqP8Q6XXklMC9AIC4U31OBUxDAbEEgoynUXUC
AwEAAaNFMEMwDgYDVR0PAQH/BAQDAgEGMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYD
VR0OBBYEFOoyh3yu3KqRZxGqp4rAGAG+yDbIMA0GCSqGSIb3DQEBCwUAA4ICAQBq
IOv2O6O87dokqFpMujjVYK2junafuns11VdUiWUv8bXPxUqJqQpXOaNHApr/SIU6
2JMVoViNjeFbctNKSeCLMGlH1iNVK550OHNCUXVeceSWPVpE1sUBXSzfEEvnbDns
T9gculoi8BhbrwPeO/TWywF9cEGg6cosCEwtTXrfdFCzEsmwO6SF4ED6Unu4L6oc
Hhzi4ZHzcTVphVIr2T0PnrCUEICrbDji41bGXUM/122DTcW0Sw605lSAYzgk6LAt
qPAAY1YYEAjhv63V4tDhfh9Gm+Q/BveCJQwxzUaRHz6rmkfPFGoxWj1yX8lP7gKO
CFVCsO94JhnuPuTWfG8CF/SIePEqcteRL/OwdOoLkWRv0rEa+PdP/lBzH+Va9tWx
h3zvjpb8XEM94FLQNHsyuC6w+lCRyIxApYEIpZt4JdtQgJz42+KDEVVLARPPjXUY
CdIUxPbLUGk3JlCEotH4LBr9V28VM2A1Hxc7LOmEXviS83ju2pGlIvyTvSwVMmTV
8WvxsBDZT9wRLofOf4/38ezpeHX79+eWXyNoH+7vLytAC9CPkSvS5Op83G0o95SR
VsOCRQH71miWXhy8UKq4MaKZayGNZiJI3ZVQjUiY+nYIOUknIg4dYTqEmjfx/lav
NryJ4883lMzeNeP6xi4P/dwDqVz//pKiI4qEq6l2OA==
-----END CERTIFICATE-----
'@
# Bingoo logo as a 128px .ico (public branding asset, base64)
$IcoB64 = 'AAABAAEAgIAAAAEAIAAaFgAAFgAAAIlQTkcNChoKAAAADUlIRFIAAACAAAAAgAgGAAAAwz5hywAAAAlwSFlzAAAOxAAADsQBlSsOGwAAFcxJREFUeNrtnXt8VNW1x7/7zGQSQhJCgBhCICAIIUVUHj4oFiTU17UmSr2+FVu1Lx+11AfWSnsFLVprheurigGUWi+KEy0PURARFRAUKIZMxECATB6EkIQwmUxmzrp/JCqGmZAzmUlm9Pw+n/yRM2fPXnPWb6+99tpr7aP4DiNjWK6mW1WqgiyBCUpxGnCyQDqQrCAe0IF6oEagREGRCJ8BG4F9MY3SUFpa8J19Ruq7+KPSs/JSFExFcQUwgRaFG4UbKBJYDbwSo8uOUkeBbhIgQjFgRC5AAkr9EsWvgEGANURf3wBsFOEJFKucu+y6SYBIUv7I3ARE/QzFvUGOdiNYC8xEly1l3wGLoKJf+XljgCdbTb0W4Da91ZwXIhSh2KOgqtXMawJJrcQ5RcEoIBtIPYFFmIcuc8scBfUmAbrDwRuZaxVR01E8AvT1q3Rhtyj+BRSIsFuU1lCxa5nevv+QaxNUsoIJKK5UcCGQHIBUH4nOz0WkuLy4wCRA1zl5uXFKqceA2/x87BXYiPCExyNvVpcUeDvTV//hualKU3cqxQ1Ahp9bqhG5oqyoYJ1JgC5A/xG58UqpJ5XiZ35M/l6BWT6d1yoddldI+83KO1lTzABu9eNcVgnc5NxlX2ESILzOng3Uk61KOFb5usAygbuUkgPOwvCY47QRuZqmqYsVPNW6ymhjCbgSkbVljgKTAKFG5ohcrVlTjyi4p+16XYQ5PpG/VDo6Z+47bA2y8wZrQj4wuc1HNbpOTrnDvi1anqsWLYJ6lfqpgjvaXHYBdwEPd5XyAcoL7XulJcj0bpuPUjSN/P5ZeSnR8lwtUWH6s/KGo3gJ6HPMZQ9wpwjPO4u6PjBz5GCRKzE1620FY4Ehx84UQK9efUa8XX/IEfFxgoifAtKz8qxKsRTIO3bO14VHy4vsM7tdvpF5GQrWAMPbTEvTjjbKiroI30eI+ClAKc4HLj72msBagTmRIJ9zl/2AwC9ap6OvEKcUc+JsKs70AToT7MnKjQMeaiNnlQgzKorsDRHzEBXrgXltLp9usXC5SYBOQJS6BBjdZrn3ZHmRfUckyXmg0K6L8ARQ1MZ63Zs2PC/eJEAwo394rhW4vk3QpUIXno1EeZ1F9iqBJ2gJEX+FbE3jfJMAwYx+ixredp0twlMVRfaaSJVZ1+Vl4MAxl6zAjf2G5WkmAQwzgAtp2aX7CvUCL0fyaKpwFLiABW2mgQlWq989BJMA7Sz9NBQXtPH8N+iCM9KdKoFlwLFBqVQFY0wCGBFKIx44u83ltyoddi+Rz4ASYFubaEtOv6G5JgE6PpeS1cb8u2hJ0ox4xMSKP1lPt1qVZhKgo1Bkt7lSpQv7ooEApdsLoCWr+Fhko0KWn/jdJ4CCU9pcqna7pZYogSiKj/UDFKSIqIQIfdZd+GDyc6yiGIwwCkU2IkNAHbdzdou9x+hVxdZhX/1/9kBfxdKrXR9FCwE+KbMkX/d/PSa7mr8x+8uuca0en+HzE72UBqWUU4TPUbJDoe1W0991dZWs1i5SfLzAVQLXI4wCUhC0QPyzafKt/wcl62kQ+WHVr3BST6FHDLiav7lmCRgQUoh8bToaBCnR83NeVRqL1Y1rDkQ1AfT8nHjgBoF7gcEdbeeTNo5V1GQtfKVsQWvD7eaObQwn0BL6Hi06M/X8nKeBx7Wb1lRFnQ+g5+cMB16nJX1qMCaMIoGW7KcP9Pwp50v+BVrUEEDPnzIZeIeWlGrN1GWnMBzUUsF7q+T/WIt4Auj5U6aCeoPjkyZNBI8kYL6g3xFqS6CFVvk540AtwX8hhYnO+2tzBe91EbkMlEU5KaKzEjgzOM8pBjX2VhgwlqqjVo4e40EnxkLfeB3qDyBbF8DhPZGpot5DUONuxdszHWe9+pYz2z9RiNOawfkpsvV58DUH20uVQi5SN639NGIIoOdP0UA9Bvwu6C857XrUGTee+L6DhcjKGaA3R9j4jENd/CSkDD3xYNn8DBS+3pneNijFBWr6mk7HC0IyBSilsmkp1uiEz5vasftie4E1NvJGf0wPsCWE9rcGxgSRb+dJdisBRPhV67IleHy5BnyeE3Yk+z4Ez9HII4C7Fg5s4puoTqAghwdK3guF3mZI/uROx3E6PQXIwpwUERz4r9A1hpSh0DcLtADlCkfKwbkVJELT7TUrDBgPPfsFmCt9ULkD6vaHojcPiou06WvWdtaz7OzonwKEphKm5suWv2iF7oX9H3dVbzaEXFoOrOjWKeA8zGBPd2Gi5E+K6zYCSP55ViI43el7gAxRlrTuswBKSwrJ3G8iWCQjnQu6ddZ0J/Ht1C0TXQsbSqWZBPh+Y1C3EUCEFCDO1EE3QqQ7fYCwn8ln4sTIlCVTuocAytzyjQCoNLxi7RYCiMgAUwHdjjRQXU8Ayc/RQGWYz7/7CSB6d1gAJVbaP07VRNcgFaVs3UAArJhBoEiAVXViIAZPAFGmBfgOxAKC9wFEkjBz/yIkFBD8cjxoAiilzBhA5KDrCQCYK4DIiQVkmgQwLUBX+wCYQaDIQUZ3WIA087lHjgWQRcEliHYmDjDYfO4RgziUJaiYTPBJoSLp4TxfoskLRdUWHAc19tUq9tdrHDyq0dAErubIPOPaokGiTejVQ0hLEAb20hmaojMy1UdGkoSzayu6pAIVXUIAyZ8aL0hIz8R3NSt2H9JYt8fCh6VWtpVbaPKBroNE8dBUCiwK0hJ1zhnoY9IQL2dl+OjXU7CELpXWJhJcUC44C6CkLxKawyV2H9JYUWxl1RdWPq+0oEeztv07y3gFDtRpLK3TWLozhl5xwsRMH5eMaGbKUC/xMSGwAASXGhYsAVKR4DcgBNhebuGlbTG8/YWVOvd38g22AVHnVix3WFlZbCWrn4/Lsr1ce1ozibGdYH+QyTnBEUBUajBtRWBvrcaj62NZ+YUVn873GrpAYZWFwioLz262cc+5TVw60kuCLSgiZHYZAUTIMNq2vknx4lYbC7bEUPs9G/EdwSGX4r7VcSzd6eOec5s4Z5AvCKe861YBhuabnZUW7ns7lh2VlhPWTka+U6fo1zeZwYPS6JPSi6TEnsT3iAUUTR4P9fVHqT5UxwFnFWXl1YiBHywCW8osTH+9B9PHNHPHOR56dtgaqDRZMkVT167Vw08AxYCOuOYeH7yy3cac9200NkfnqE9MiGfEKYP44VmnMu6MkZwxehjJvRI71Paoq5Gi4lI2b93F+o+2U1i0h5raIyckhatZ8fQmGx/vs/C3i90M69MhnSbTTBJQa0yVwcxd+TnLgYvb/fEexZx1sfxze8xxx75FA7JHDObSiycydfI4hg8bhKY6R2ARocx5kHUbPmPZv99ny2cOdP3Eiu2fKPztIjcTB5/wnGynUnKWmr72QFgJIPk5NoEPgXGB7jnSpJixMo5VxdaoWsMrpRg5PJNf33w5UyeNIyGhR1j6cbs9bNlWxP/+43U+3PSfE1qE3j2E/8lpIje7uT2FuUAfr930XmGYpwBJABWwGqjWrfjt8jjWfGmNqhHfP60PM667issvmYTNFhPWvuLibEw8ezQTzx7N22s28dj8V3B8sS8gEQ43Ku5aEUedG24YE5AE8SjNcHDOuJY0ktD9ZwIdaVL8bkV0Kd9qtXDpRRO5585ryUjv1+X9X5BzFmeOzeaZF9/gH/lv4vX59/69Ojy0Lo6kOLgsO+D5SBnG1Wl0/Osk4Kce0OOD+9+J5d3d0aP8uDgbc/54K3+dfVu3KP9rE5+cyH2/vZ4X5t/HSf0CD+ImL8xaE8uG0kAvfDW+FAwiGq2OqwfUBeZ/HMubu2KiZs7PHJTGKy/8iWuvOB9bTPeTVtMUUyeP47XFsxn9g2EB7zvcqLjz3z0oqfGjOmFgFxDg+JDjeyVWnttsi5o4/rCTB/DcE/cwfszIiJNtSGZ/Fj/7AJMnnhHwnqqjintWxdHgaesNqAxZPFkLKwGU+vY8c/Co4v7VcTR6o2TkD0xj4dN/YNTIIRErY98+vZg397ecd27gw1c2l1l4brOtrcXti1i0cFuAAcc6JrPXxeI8Eh1BniGZ6bww/z4GD+of8bKm9E7iqb/+jnPGjwoQV4AXtsSwef+3/IF0EWNlYoYIIPlTtNZ9AAA+2GtlZXFMVCi/V1ICj8+5jZHDM4kWJCX25O+P3BGQsA0exdz1sbi96pvp2eA2vTHvR2FFWvYB3F7F3z+y0dgc+Q9S0xSz7ruJ8Wdkdep7dF040uDik08L2bilkOLd+3CWH6KuvgERISmxJ/36JXPKyQM5c+xIJpw5ipTeSWha8JkfA9L78fjs27jpNw9Tf+T4AzK3lFl4q8jKFaOaARLQSAAaOq5SIxZg0ZQ40dV2YPjL22KYuTo6Dge5Iu88Hp99O5qmglS8jmP3fhYuWc7y1R9TW9ex5xvfI47zp4znhqsuZOzpWVg6kQL04svL+fPcF/H52UMf1kdn+Q0u4mMEpXGWunHN5vBMATpWILXJC/mf2qJC+ZkD0/j97VcHrfyaw/U8MPt5fnrDH1iy9J0OKx/A1ejGvvwDrrn5z9w1cx7lFdVB/45rrvgxE8481e9ne2o03iqyturIWCzAICVVEpC8bo/V/zo0wmDRNG6/dRoD+hsP8ogIn20v5vLr7mfxv1ZRVx/8+cTuJg/L/v0+l14zk3fe+8TQFvHXQatYGw/cfSNxcccPPJ/Awk9jvloWDgobARSS7tVhyfYYvFGQzTPm9BFc9pNJQSn/zRUbuO4XD7F7T1nI5CmvqOaWO+fy6rI16EEETUYOH8x1V/h/+ZjjoIWtZRZADQifBVAqo6RGa+0o8vGL6ZcSG8TGzsp3NnL3g09RV98Qcpm8Xh8PPryAV5e9a9gSaJriuisvpHfy8fkIzTq8UWjFJ2G0ACKkr9tjpb4p8tf9Q4cM4Lxzxxput33nbu5/6DlcjU1hk83V6GbWIwtY8/6WIH5XesAo4btfWjncqMLnA7i9DFwdJZs9/503hdhYY6P/UE09dz/4FNWH6sIun6uxibsffBqnQcdQKcWVl00hxnq8HurcivV7LBmSP0kLCwEOuVT6zsrId/56xscxdfI4w+2eXrCMwqK9XSbnwepaZj+2yO/Srj2MH5NNxgD/ju26PdYkrFpCWAiwbo910FFP5Jv/4cMGMSTTWLi3dF8Fi15Z2eWyrlqziTXrtxpqExsbw6Qf+p8GNu232pqatb5hIcCOcktUHAp11thsw1k9zy9+E7fb0+WyejzNLFyyAk+zsd20H004ze/1apeyPrrOlhIWAuyq1iL+YGhNKUaPGmqoTWVVDSve2dhtMm/aWsievU5DbU479RSs1uNXY80+bEu2hYEAmaflUlKjRXz4Ly7OxrCTjWVGbdnm6BLHLxCamjysXrvZUJukhHi/WUwC1iMeNSjkBPA2kVznVhG/BIiNtRmO/G385PMOpWiHExs27jB0f4zNSubAAPU5ihEhJ0Dr0fARbwGSEnuSlBhvKDDj+KK02+XeuavEEAmtFgup/XoHUFbHS8U77gMolQxEvAVISUlCGSjiOOpqpPLg4W6X+0hDo+FpqG9Kr0AWICyrgIRoIEDPHsbeKurxeGk42hgBkguHaowRIDGxZwD9d/wtLkYIEBWvhuvRw1iOgtfro6nJEwn6N7wUDMmqycC9UVHNr6K48lx1g/CaAYK6ooUEUap9evcy9vplt7upPV2FlgCq5Uu9pqbCA4tF46RUY6V9hwNnJ9WGngC6VAMeU1XhwbCTMwyFr3Vdp7LyUKCPnSEngFdRjYFsUxPGcNa4bEP3Nzd7KSsPuJW8J+QEqHQU6ALFpqrCgwumnGno/iaPlz2l5X7HKsLusCztFOwwVRV6jByeyemnnmKozS7HXlyNbn8fuXTCRACETaa6Qr/0u2paDokJ8YbabdzyeaCPqn0+KQkPAWCjuRIILbJHDObyn0w2OA5pb/dw88EvCrxhIYC0HEb8qam20MBmi+GP90z3m+XbHooceynevT/A6oDlRr7LEAFiYsUDrDZVFxrT/5ubL2Pi2aMNt12++mPcTX6DQG5gbdgIULq9AKCgtSMTnVD+tEsncdst0wy3rTlcz5srNwQ6cHMdUBU2AgBomtoJbDbV2DnlP/zgL4mNNZ5eUbBiAyX+08d0EfLLHXZvWAmw//M33AIvYe4LBDXn/+bmy5j751+3Hi9rDNWHavnHwoJA/lmxGDT/EOT+vtLlNTT1BzBfG9PRUZ85MI1Z997ElB+NDapMXIBnXrSzvyyghX/N1SjVXUKAMkdB7YCsvMdRzDfV287DtVg4ZWgGV06bytXTptIzPvjzFDZu3tle3UKVCM/UlRbQJQRoxcvAXcDJpqpB0zTie8TSJ6UXmQNP4tQfDOXcc07j9FHDSDAY5GmLyoM1zHpkQcC6BYH55UV2Z1AkDVoqkVpRapaCfKIgVSwQTkrtzQcrnw6qZt8fCWJiLMTabH5z9oOBx9PMA7OfZ5djb6BbihHmBW2lgm1Y5iig/8jc1xTqSuCSaB65RgMxXYXmZi9z/rqIlYGLVjwi3OssstcH/fs7I2D5rgI3iplAjTkJhBY+n84Li99i0b9WtecYvgas6NQA6KygZYX2nQIzMfcIQjry5z23lEfn/ROvN+CrY4p0nRnOInunknRCMncrxYsijFfwM6IkezhScfRoI3/5+8vkL2l3YNfqws8rHPaKzvYXEmWVFdq9CPfSEoo0ESQqqmr49e//xqJXVrV3m1tghk+XkFSzhmy0OovsNaK4EdhmqtIYvD4fa9dvJffqe1nz/pb2SsS8uvCQwMKq4gI9oggA4Cy0HxBFLrDFVGvHsHdfOff96Vl+fvtf2svxg5ZY/zxd5NHyXfaQheFDvn53Ftr3pWfnXamEpcAYU8X+cehwPf9cupoFLy2n+tAJs7i9IjzqEx6qdBSE1NkOSwDHWWgv6T8y978UapGCqaZj2AJXo5uSvU5eXbaW199cx5EGV0cCUA0Cd4qwsNJhD/kGXNgieOW7Cir6Z+VNQzFHwS+JgtLycCznnBXV7C0tZ8Om/7D+o20UFZd2+FAogRKEXzmL7GFLwgl7MVp6dp4NIU/BfAjuFedGkD1iMDdefVG3KLzJ4+FIQyMVlYeoPHiY0v0VVFfXUlvfYPgkMAG7CHe7GmV3MJs8EUMAaDleprlJDVKKucDl30drYAAHdGGWgoXOInvYcy66tBz1pBG5mkVTlwAzFZxp+gbfQo3Ayz6dxysd9n1d1Wm3FFOnj8hNQKkLUcxU5krBJbBQhPmuRimuKy3o0kyrbq2m75+VpynFVIRblOJHQN/viVVwA8UivILOYl3hrHDYu0WQiDlOoX9WXoaCySh+0moVBhPFeQZ+UA8UCqwXYblPZ0tVsd3V3UJF3HkaJ43I1SxKJaPIUDBa4AcKshEGoegrkKIgPkKV7BFwI1QoqEBRIoKDlmKaIoQqp8MeUSn1/w9cD92ZrQtcHAAAAABJRU5ErkJggg=='

# ---- self-elevate -------------------------------------------------------------------------------------------
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host 'Relaunching as administrator (click Yes on the prompt)...' -ForegroundColor Yellow
    Start-Process powershell -Verb RunAs -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -NoExit -File "' + $PSCommandPath + '"')
    exit
}

$result = [ordered]@{}

# ---- 1. CA -> Trusted Root (LocalMachine) ----------------------------------------------------------------------
$caFile = Join-Path $env:TEMP 'bingoo-edge-branch-ca-khatri-01.crt'
Set-Content -Path $caFile -Value $CaPem -Encoding ASCII
$have = Get-ChildItem Cert:\LocalMachine\Root | Where-Object Thumbprint -eq $CaThumb
if (-not $have) { Import-Certificate -FilePath $caFile -CertStoreLocation Cert:\LocalMachine\Root | Out-Null; $result.ca = 'installed' } else { $result.ca = 'already trusted' }
Remove-Item $caFile -ErrorAction SilentlyContinue

# ---- 2. hosts line --------------------------------------------------------------------------------------------
$hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
$lines = @(Get-Content $hosts -ErrorAction SilentlyContinue)
$keep  = $lines | Where-Object { $_ -notmatch "\s$([regex]::Escape($EdgeHost))\s*$" }
$keep += "$EdgeIp  $EdgeHost"
if (($keep -join "`n") -ne ($lines -join "`n")) { Set-Content -Path $hosts -Value $keep -Encoding ASCII; $result.hosts = 'written' } else { $result.hosts = 'already present' }
ipconfig /flushdns | Out-Null

# ---- 3. shortcuts for all users (Bingoo icon) ------------------------------------------------------------------
$iconDir = Join-Path $env:ProgramData 'BingooPOS'
New-Item -ItemType Directory -Force -Path $iconDir | Out-Null
$iconPath = Join-Path $iconDir 'bingoo.ico'
[IO.File]::WriteAllBytes($iconPath, [Convert]::FromBase64String($IcoB64))
$desktop = Join-Path $env:PUBLIC 'Desktop'
$links = [ordered]@{
    'BINGOO Online'        = 'https://khatribiryani.bingoopos.com/pos'
    'BINGOO Offline'       = "https://$EdgeHost`:$Port/edge/local/login"
    'Branch Server Status' = "https://$EdgeHost`:$Port/edge/local/pos/health"
}
foreach ($name in $links.Keys) {
    $p = Join-Path $desktop ($name + '.url')
    Set-Content -Path $p -Value @("[InternetShortcut]", "URL=$($links[$name])", "IconFile=$iconPath", "IconIndex=0") -Encoding ASCII
    # a per-user copy may exist from an earlier run without the icon: refresh it too
    $u = Join-Path ([Environment]::GetFolderPath('Desktop')) ($name + '.url')
    if (Test-Path $u) { Set-Content -Path $u -Value @("[InternetShortcut]", "URL=$($links[$name])", "IconFile=$iconPath", "IconIndex=0") -Encoding ASCII }
}
$result.shortcuts = "3 on $desktop (icon $iconPath)"
# refresh the icon cache so the new icon shows at once
try { ie4uinit.exe -show } catch {}

# ---- 4. verify with full TLS validation ------------------------------------------------------------------------
foreach ($u in @("https://$EdgeHost`:$Port/edge/local/health", "https://$EdgeIp`:$Port/edge/local/health")) {
    try { $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 15; $result[$u] = "OK $($r.StatusCode)" }
    catch { $result[$u] = "FAILED: $($_.Exception.Message)" }
}

Write-Host ''
Write-Host '==== Khatri counter setup ====' -ForegroundColor Cyan
$result.GetEnumerator() | ForEach-Object { '{0,-52} {1}' -f $_.Key, $_.Value }
if (($result.Values | Where-Object { $_ -like 'FAILED*' })) {
    Write-Host 'Something failed: check that this PC is on the shop LAN (192.168.100.x) and the Branch Server is on.' -ForegroundColor Red
} else {
    Write-Host 'All good. Close and reopen Chrome, then use the three desktop shortcuts.' -ForegroundColor Green
}
