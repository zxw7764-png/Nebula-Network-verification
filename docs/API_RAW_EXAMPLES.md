# 客户端 API 原始请求/响应 JSON（加密信封原样）

> ⚠️ **本文所有示例为已废弃的 3.0 信封格式，仅作历史留档。**
> 3.1 协议（ECDH 握手 + AES-256-GCM 信封）的报文结构见 [API.md](API.md) 第一节；
> 3.0 请求会被 3.1 服务端拒绝（`1001`）。解密后**业务 JSON 的字段结构不变**，
> 下文示例中对解密后业务数据的描述仍然有效。

## action=init

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhePlHDn36rzQScDae47N7O/Xe1r1yCQH3N27DVh9eDQFftsJTzNvTUd21vFk01m0DJnx8JQR8ohGoHWdmlxO+R4=",
    "sign": "c3213b57c97f9c80d17e43d8e771c87165b38d70fa30d080bb1c47d15016c227",
    "t": 1789019337,
    "n": "41d61986657d4609"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFkFScAQbT7IGog4KHRNErrOAPB7xCVuoWc6IOjpwlBmVYRO+qd31Xz7MROT1EYDayyFXpXDaiSlllAYJM4CrTtGOke6INd754ARwf2lam8v1sqBK0t3+qzoz2iVaBxned51sz4O3LVteMtP54/GZTGZhDYBrStFZjuAvZ1XfsjDGqrc4JOdG5svxZiPZs2pk8H7L3XLfQCwOKirYjOBxIUggF2X2i/gfaz/rHD0dFjEvVqiqZK0Dp2FsbNfnYZ7gb0fAV4/ZLVAZPxXqUaX7YouqElW++VVaBfpLOnd6WwgsrzDqtWwsme3DsyunhW+cf0P4PeVTwpd7nEP1lz378ewMleprYG1T/Jfwzo3lF77AAwjiaEvC+rLn1wzy2OpYhyCdzLvtikZX6z4ljV+zVrV+sZLGfXOAZ7h3OUaj9MIqFbTRW6QEdaIWqWTbCCGbHhavZyzH5kYpOiEm5hfelelCpMy3j8ClE4NjnIlc9ZCEOG/7kFNXVhHRhorjTNJXcQBvU6phwLAvXuaiyxY7cMZDFZyHXRO6+MVlEVuJcb9I5FcoT4VpU8SX6HXNuhRG98KtU3Ouu2jupQTU2Vua+KhcGTauI0/NEmvBhtZq3dkMq1xI+gA03zghyA3f7IRUec4QVy8GKGQRZDtnZQWpfKnGE/B05whlP0FKPGDVyVvrBQi8dbTGPGxG9bzSeCU5mbLzfqg6nS/OTzQKibvG5CY",
    "sign": "6eebd9fad6d8282e5787e2dc1a5c20eb014ce080c536cc529f6a1462a0a0e256",
    "t": 1789019337,
    "n": "73811dfa5b772607",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "ok",
    "time": 1789019337,
    "data": {
        "server_time": 1789019337,
        "app_key": "SWDEFAULT",
        "software": { "id": 1, "name": "默认软件" },
        "site_name": "Nebula Menu",
        "heartbeat_interval": 60,
        "session_ttl": 3600,
        "register_enable": true,
        "maintain_mode": false,
        "login": {
            "method": "password",
            "label": "用户名 + 密码",
            "need_username": true,
            "need_password": true,
            "need_code": false,
            "fields": ["username", "password"]
        },
        "device_fp": {
            "enable": true,
            "components": ["board", "cpu", "disk", "bios", "gpu", "mac"],
            "core": ["board", "cpu"],
            "weights": { "board": 30, "cpu": 25, "disk": 20, "bios": 15, "gpu": 10, "mac": 10 }
        },
        "session": {
            "k": "73811dfa5b772607",
            "s": "0123456789abcdef0123456789abcdef0123456789abcdef"
        },
        "grace": {
            "enable": true,
            "seconds": 3600,
            "max_seconds": 7200,
            "algorithm": "ES256",
            "kid": "3f9a1c2b",
            "public_key": "-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----",
            "ticket_prefix": "G1"
        },
        "crypto": {
            "enforce": true,
            "algo": "ECDH P-256 + AES-256-GCM",
            "sign": "ES256",
            "time_window": 300
        },
        "version": {
            "client_ver": "1.0.0",
            "latest": "1.0.0",
            "min": "1.0.0",
            "need_update": false,
            "force_update": false,
            "update_url": "",
            "download_url": "",
            "update_note": "",
            "changelog": "",
            "file_hash": "",
            "file_size": 0,
            "self_file_hash": "",
            "self_file_size": 0
        },
        "notices": [
            {
                "id": 6,
                "title": "公告",
                "content": "感谢你使用Nebula菜单",
                "type": 4
            }
        ]
    }
}
```

## action=notice

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhYY3rwOMG130pveypb/PDK8=",
    "sign": "1b7d18c2c210a827948f59a9b6fa7e8abde2de51fcb5d36c2826b4c887d6a724",
    "t": 1789019337,
    "n": "f607a5d6fb1d4a0b"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFkFScAQbT7IGog4KHRNErrOAPB7xCVuoWc6IOjpwlBmVVG/SwEfOTT31SKRQP8cXA2Cd718GAxKw3lYhATRkQNW40YIgbJ/JyVFm354y6NWi1cp+zQeiJBNBouuTWRetWjbcrVT/RqhRgIYzReoYxAFbqZUpskJxDVdRMuggseZhge97M9yvyE6Md8LuujpqbG/T1nsOBgamHBFJ2fWzU8YNXlodH26CNDJ+eYFpNLEQUd/D6HpMOD6tnODWJKvyh2ha/KDb3asn3vjNXjU/sT1sPViEPTchbv2s263XEmMww==",
    "sign": "2d937adee545862d58f19b571288424a12da790fbfb824cf1895298da63e7849",
    "t": 1789019337,
    "n": "5adf736786e13f95",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "ok",
    "time": 1789019337,
    "data": {
        "list": [
            {
                "id": 6,
                "title": "公告",
                "content": "感谢你使用Nebula菜单",
                "type": 1,
                "created_at": 1789010070,
                "created_at_text": "2026-09-10 11:14:30",
                "type_text": "普通"
            }
        ],
        "total": 1
    }
}
```

## action=version

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhWCuRVeZUd5qa4IMTd/isdAFuvjUb62sOpVhcuGeDNqlqGbM3JgIhLkXObdwc9blaQ==",
    "sign": "00fc97c59e8f9c53f09d6d67f78fe84db8095f4d6b6013f12edc03fcd6621783",
    "t": 1789019337,
    "n": "f3ccef2f32ee4838"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFngkF8RFHa7Vvp87Ykg4QfK+9YAVG0MHbqIDpJZa8dZklnc+rifx/vnev1ztG5CdgiJls6Jdfy+stcbckSzQRv6AXFS2KMnxS2mKdR0w+kJVHpX2wSsWXM2Ez5lSw6+wruOKsmgKJf8+6Q8pSNF8tI11jWBWTG6TIKBBAOclrpeh1Avst31DWdNQX8J3qt8R6jqXA08NDvkZ3emFgVDa3aVVm06/hgRb3iXs1VcN2c7v3g1tXpwNxCDpQTp+2ZkT92LgOBtbVxSisede3GeqwpxnPX6Px1npCgIJr/I2XXE7Q==",
    "sign": "b9bda37d0576e55804ca57568698c8e8adae10514a76a379f9b441afbcbbc57f",
    "t": 1789019337,
    "n": "6d590a71e3c6a02a",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "已是最新版本",
    "time": 1789019337,
    "data": {
        "current": "1.0.0",
        "latest": "1.0.0",
        "min": "1.0.0",
        "channel": "stable",
        "need_update": false,
        "force_update": false,
        "download_url": "",
        "file_hash": "",
        "file_size": 0,
        "changelog": ""
    }
}
```

## action=register

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhaidWKOCMe+KxYKFyNInnj4Nsu6e4lS5/EyWXTAv8llzBXIaTeV2SFDrM/Vp6krHLCy/VQA6kGgXPlZFEhZmCIubxQbVsInKaaKhi7ZXsUlc",
    "sign": "f0cb03c21680c2fb9653833d4910781a49697304e51c35778899b4653c5ee0c8",
    "t": 1789019337,
    "n": "a34d8531b4d63883"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFlLXEQiZ5oiw5bvJchpE1E7F+8KQwWsygl2wVlsjGfQcj62UTexLQuK2DeVuzoxxfbQ/dZACXPe0PELltXEcRkRsdo+3G99/nDWqFPw0mAXQlSNlGOslhFVcqpSgDnqOvg=",
    "sign": "5ad24bd9777e853773cb3b79f1a8087744cc679e0a87a78e2aa4c6188032b872",
    "t": 1789019337,
    "n": "e2cd56d6bbb05abd",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "注册成功",
    "time": 1789019337,
    "data": {
        "user_id": 61,
        "username": "capture8024fc"
    }
}
```

## action=login

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhaidWKOCMe+KxYKFyNInnj4Nsu6e4lS5/EyWXTAv8llzBXIaTeV2SFDrM/Vp6krHLAnZJyQyIJz8sjcSX2rFs0tErT/8OSS6oTw66L+hbXAknsRLHbOUxiZsn4sByb3UxXvOt76CBsxWE2xC8m06ANLxN4XxRYa7qOF8yWrgFvqTXzddMDyxYdbwV4jZ5Ju0Ew==",
    "sign": "a28f4f32de286610c102ad96d44518992d25c9ab13f840e460b507c0ea3e6978",
    "t": 1789019337,
    "n": "1f7a5082280cab1b"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFmVHFB49puepGFzrfCIj12qbD3XjlvogdsQJ1etcaRmw+fbtvbkHSzY69xLOKC4Nv0yVbpn2oEvWeK7Po9aT1ySufb1XedYMITMB+e5ERl1EjmdrfGnpZWnup8jOtZWaKPVh3T2yKThdTHYOKX46OK8Gt3ocjR+LQ3MqE1d4ShpbDMOIag/qxpNaf5SD883uEM3m8Qt6EyAGgUeTyKeONVjMwFWb/QRtuJSUoNUo/sGGlBACE0o0v4ZuFLtEoTNM/DFlaxx6RNj9Y2zd5Be/lYqRf7EUcexH5yWt2xHdyS62HLJm1M/UtVzMOFzvN9u6/Gm5raV2EPzz8KVFcNRWxQ7Pzsxa5B/h4RpWYhxH4XuV5NuOO9oJrNjBbF1EuyX/AduzMzoobf+IOk7N8kbhXq5KEPayI4FFxSa/2OCFVk09F/CIL9XQCxQHMKOtFlTxQWIfrXoUkjA4PT3ML1UGqFrKqDQnknZCsd+Z+EK4l4Pp1XDtrKbV1dPL83Kkzbn1ht29UIFlsKijCl7K4jBkmVP3crAPcZ9cxtWt488UmB1lrFPHqE2LQJCsD3ROnIHYqEE5LGxbhomb2hYvGs77/zUfSaMlZ46vT3PcODMyZBRU4pGdS11OfShrFIwBYtCyLQHjP+61aYfgiSKa3/SfKWa",
    "sign": "7e917e94340592ca6cb29da8ea36696c0648ff927b0c2872b2b2ff31d00cd5f2",
    "t": 1789019337,
    "n": "8d86f01d13708181",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "登录成功",
    "time": 1789019337,
    "data": {
        "token": "c61b44e10233bfa4174cc3ce1ea9fb0c150a84a0900f471ff5a5e3e87e44eac0",
        "expire_at": 1789022937,
        "ttl": 3600,
        "login_method": "password",
        "account_created": false,
        "user": {
            "user_id": 61,
            "username": "capture8024fc",
            "nickname": "capture8024fc",
            "vip_expire": -1,
            "vip_text": "永久",
            "points": 0,
            "max_devices": 1,
            "status": 1,
            "group_id": 1
        },
        "vip": {
            "valid": true,
            "code": 0,
            "msg": "永久会员",
            "expire_at": -1,
            "points": 0
        },
        "device": {
            "machine_id": "DEMO-MACHINE-0001",
            "auto_bound": true,
            "max_devices": 1,
            "bound_count": 1,
            "risk": []
        },
        "grace": {
            "ticket": "G1.eyJ2IjoxLCJ1Ijo2MSwibSI6ImRlbW8iLCJrIjoiYWNjIiwiZSI6LTEsImkiOjE3ODkwMTkzMzd9.dGVzdA==",
            "until": 1789022937,
            "seconds": 3600,
            "issued_at": 1789019337,
            "server_time": 1789019337,
            "algorithm": "ES256",
            "kid": "3f9a1c2b",
            "machine_bind": "demo",
            "public_key": "-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----"
        }
    }
}
```

## action=userinfo

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhbGI+tZt6zXKC00d3VEEFHxzsJZ09qORU0LG7P5OSMv3yde8d1D4s6nYikC/A+KjHQHl0XjrGLPxkxuclUqT7VT6VkUfXr3njmQC9p7fZiv/",
    "sign": "cd67f37d7f0ba48c0ac043ffaff385756c9f78a8f9ec8f14120ede4ac0595175",
    "t": 1789019337,
    "n": "822fafa409f959f3"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFkFScAQbT7IGog4KHRNErrOAPB7xCVuoWc6IOjpwlBmVXePMNOAJW8xLQ+kFhzJ8w011joG1KcEdyjEwZGvJUD2e8DrPVOdMm7U17D1soIHhiWeHuvZ9bE+Fzva/o9yUOu+vFewAcukWzlYvRfTXF5KUN5WxT5KYu1Ac7lTOFC9PWeR/RjC6DWQXboBXda3dGLuTQsfA/6FjUNCLB779utXaw8IoseoYMj+eVyP9+LoaQaDc0rTwvCsAdpY7u/KCKBLsmFR8Wa3oBSBuRpbCtMcNtcYvEgHC4BwlMEzSY8E4tCs0ihqqTQkV9f1z38qI4DEMyJqbRAt/dpYYzzdrLiFRfxffm1/f+95QMitXYnJIoD6ITrNIXWT1oC6KtZ7e6+bhnv7zV7smCOxb3GLnz+z/DQlyQ5xn6FAh0tXirdr9Zup8Q26gy5TGwN4uCiTjR3K6PoN5MP60CjM47kWnwt3YleKFy9Jg+HWWP1slkm/+79Yd3qQsdPAIFMb9UzEK1OmYkAonMDj5iUD7le8K/8bTEq2keZFQ1jnZrMNCi9IFcBooeZab47Y+5kmWEnzD8DT2KsVv5vytPzcLqxNwY7WEcacbXdGk3RL0cnKQpgbxQ==",
    "sign": "65341024dad69c66b3cd30beb459ee9d7a814ca4f760835f46ba57940ea9863b",
    "t": 1789019337,
    "n": "19ff8d175d77ef03",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "ok",
    "time": 1789019337,
    "data": {
        "user": {
            "user_id": 61,
            "username": "capture8024fc",
            "nickname": "capture8024fc",
            "vip_expire": -1,
            "vip_text": "永久",
            "points": 0,
            "max_devices": 1,
            "status": 1,
            "group_id": 1
        },
        "group_name": "默认用户组",
        "vip": {
            "valid": true,
            "code": 0,
            "msg": "永久会员",
            "expire_at": -1,
            "points": 0
        },
        "remain": -1,
        "remain_text": "永久",
        "register_time": "2026-09-10 13:48:57",
        "last_login": "2026-09-10 13:48:57",
        "device": {
            "max_devices": 1,
            "bound_count": 1
        }
    }
}
```

## action=devices

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhbGI+tZt6zXKC00d3VEEFHxzsJZ09qORU0LG7P5OSMv3yde8d1D4s6nYikC/A+KjHQHl0XjrGLPxkxuclUqT7VT6VkUfXr3njmQC9p7fZiv/",
    "sign": "25aa2132131db15306eefd401194634c6291333975ccd07273ce37a33fc57b2a",
    "t": 1789019337,
    "n": "227d0f72270430b4"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFkFScAQbT7IGog4KHRNErrOAPB7xCVuoWc6IOjpwlBmVaEcdMVbokgybYU7QP58Gd7azxEiUqlZsh0gJLqmmITu/Mu9JRMFZrzWL6wc4fQ0FJGU9Ma4ZhwTaDL3Kid1xtKBhaDwduBSPhW61FS2Olr3JrmnsfSnLNFFsqp9hRKSfpeVxwZn6++7pTtPNGjvLI5CTGiB/jt/Rrf0Q8lhyh7RkKUVPUT/kQfo1aZAYJ7HHWyBNE/Cq1EJ7gk8YgMtuk3e7Uq6fx7Ta9UdoZZOEhSO/lePTltvcewN1pEQCoEcV/nsQbRFJ0LLQfg4BwwKQFXAOF5jVkqwrVFQlszEZgn54AJR/5/sbR+chqWYW5FXT74v1NMx/ED2ZRD42WrykiOuVjNJCkHiqlO2zBnikAppfub+mObrbehGUNms4145bCl41jEF5EcU0/0NwMFQ83I=",
    "sign": "8fe53251585a33fca93abb83fcf6f544f530f43778d24fe812011861dcf251f7",
    "t": 1789019337,
    "n": "132896d0b0035df8",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "ok",
    "time": 1789019337,
    "data": {
        "max_devices": 1,
        "bound_count": 1,
        "current": "DEMO-MACHINE-0001",
        "devices": [
            {
                "id": 26,
                "machine_id": "DEMO-MACHINE-0001",
                "device_name": "DEMO-PC",
                "os_info": "Windows 10",
                "ip": "127.0.0.1",
                "status": 1,
                "status_text": "正常",
                "bind_at": "2026-09-10 13:48:57",
                "last_seen": "2026-09-10 13:48:57",
                "online": true
            }
        ]
    }
}
```

## action=heartbeat

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhbGI+tZt6zXKC00d3VEEFHxzsJZ09qORU0LG7P5OSMv3yde8d1D4s6nYikC/A+KjHQHl0XjrGLPxkxuclUqT7VTTgItFMI5B/ApMz1vYI4+lGL0Kp0rdHAf1GAjAgrJ/jHj+s9ShN+fHMhqtAjUG0DY=",
    "sign": "97a2c0f2bfabc6de59d45fe71c328c154f1e8cab6e19097e3b2eb03374605fc7",
    "t": 1789019337,
    "n": "b441d4a433f1325c"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFkFScAQbT7IGog4KHRNErrOAPB7xCVuoWc6IOjpwlBmVYdf6sWCZ7R1NmZu9qc1wrje3KPCT8UpALO93GOzq+cRh/nlwCDZrAzP2XLSaqKE++hoqzix4DzHMKpJqG3NLxi1fbrel3X/WwTuE0LqWhd2Mh9HQQEtp+QFDEwlogaQb48daHO0wSX3oyBRkyZJK3Kk4ErXmCM4JlT0/tHIT1RGtdCYYUJ2GnCLXxPeeBkRvfVINgiOptl1sMPzF7H9EvK1vEKaxE9LUvZgrHH6ZiwiV3MVEWiEpy4/Y4Wdzp1vTw==",
    "sign": "98bcd4e45bae7edb0f60c328d4fb1ddc2f87b8cf9c0fca92877edcaecb503457",
    "t": 1789019337,
    "n": "53532945a1a9e749",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "ok",
    "time": 1789019337,
    "data": {
        "online": true,
        "server_time": 1789019337,
        "next_interval": 60,
        "remain": -1,
        "remain_text": "永久",
        "vip_expire": -1,
        "points": 0,
        "session_ttl": 3600,
        "force_offline": false,
        "has_notice": false,
        "flash_notices": [],
        "grace": {
            "ticket": "G1.eyJ2IjoxLCJ1Ijo2MSwibSI6ImRlbW8iLCJrIjoiYWNjIiwiZSI6LTEsImkiOjE3ODkwMTkzMzd9.dGVzdA==",
            "until": 1789022937,
            "seconds": 3600,
            "issued_at": 1789019337,
            "server_time": 1789019337,
            "algorithm": "ES256",
            "kid": "3f9a1c2b",
            "machine_bind": "demo"
        }
    }
}
```

## action=activate

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhbGI+tZt6zXKC00d3VEEFHxzsJZ09qORU0LG7P5OSMv3yde8d1D4s6nYikC/A+KjHQHl0XjrGLPxkxuclUqT7VTTgItFMI5B/ApMz1vYI4+lGL0Kp0rdHAf1GAjAgrJ/jOgdy17eUArrnIIDej0Xps8h8hxzKe1EMduuI5emQ2XngycvuSvCvuxcfCk+mYjrBQ==",
    "sign": "f93c56b9e3987c2707104c02bfe0040c38d96f27d351e7ccf1f1236754331ca8",
    "t": 1789019337,
    "n": "f507499fa2bc299c"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFm2taikL5LWfarupLX1bSjBiniAu0BkbwQX2rDYJese8fpMlGZHlIg+ID2V9Nol15Fu2l/wc+8lf2CZkJ7QbAlUOCkrdPXhgQHbyg2fkEGP6L0+x7Sz2l40rTcGrY42BfFTQwQLhLQBexTBje5rt1NyRKtSVoLI4AaW2Wnz7JMvLm+eBxSprmUO3IFFILXhlIxMvIIod85s/ub2lToxLy83cV3Z5PEBCAevuNl7tN4lxLi5O3BhqHpOFjPH6SPpd9n3kxlGY1/uKpnq6YxSUsyOiuwOlntnit1qwsGpWp44ABqImSQt2CfcIVV2usbTaZ/NgbdEhuO5as5KS0m2ZHpNp47SB5IavYFKHKcbPGgRkw==",
    "sign": "28594fe31b9b0f8fad43ef724a860e59241dc34e6dbe12eaaadb322a6cdd835a",
    "t": 1789019337,
    "n": "8ede2771f8f14c59",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "激活成功：开通永久会员",
    "time": 1789019337,
    "data": {
        "card_type": 4,
        "detail": "开通永久会员",
        "user": {
            "user_id": 61,
            "username": "capture8024fc",
            "nickname": "capture8024fc",
            "vip_expire": -1,
            "vip_text": "永久",
            "points": 0,
            "max_devices": 2,
            "status": 1,
            "group_id": 1
        }
    }
}
```

## action=unbind

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhbGI+tZt6zXKC00d3VEEFHxzsJZ09qORU0LG7P5OSMv3yde8d1D4s6nYikC/A+KjHQHl0XjrGLPxkxuclUqT7VTTgItFMI5B/ApMz1vYI4+lGL0Kp0rdHAf1GAjAgrJ/jNqkACd/GcpCGWbgx62P9H2GpYVem1P/9zwX8R/he7lNedePUt5nsHSAo6i2O+NBUPWLrxD/tVC65Ytpe02E2k4=",
    "sign": "2d1e72c90e26c31b4c0990f59326de10bf237968e273939e172d1c094b9c6c9e",
    "t": 1789019337,
    "n": "700e7e5645db50b5"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFmvauvJKG+4LSSrJQMxfYrPnWqZYsBzsApQpTtH0Yl/5qfBkZicJFz4fKcvXtKArTRZ6+ypo5kd/9chc+LZQGrZaK8qju5DuF+oLuHn8p+LwdLEO8xsoet1wE0s+RtmTrMpDRCAaBDWEW/DJaUoIWzLwWQy4eFrzj/oHLlt6/7VqVSwX+ULkvFgobZTa0wMpmk3yHrs2UlUgdS5YYJBxOyCqjXkuobB1CuaPf9U6XTI7TvUKFZOMyXr0usDnByoASLKGq1es1s56+9vX8cVNJBWXbdpG3nVjesIMeTXmuDm8v4ZQ2WCvEBiknS48m9pmjNWzhuRQYDv6XOlQv6PzBUo8EdvEGxLdBMl1bA6lhOqdeve5QR6VZL3ib3ykgexqFWuqgZ9tIOHqdg10GFCvsPx1U3oHoMlc55IsjYh/WBQzyw5pOJblKMudU/KAo4njZ3kNqO03exYDXXs9/rWOAt2",
    "sign": "2bf8e082ff70aab72061e1b0d7c8b2ad56416c0a495c0332dc4953b3c4dad6d0",
    "t": 1789019337,
    "n": "b3a9fa7cc71abdc0",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "设备解绑成功",
    "time": 1789019337,
    "data": {
        "machine_id": "DEMO-MACHINE-0001",
        "bound_count": 0,
        "devices": [
            {
                "id": 26,
                "user_id": 61,
                "machine_id": "DEMO-MACHINE-0001",
                "device_name": "DEMO-PC",
                "os_info": "Windows 10",
                "ip": "127.0.0.1",
                "status": 0,
                "bind_at": 1789019337,
                "last_seen": 1789019337,
                "unbind_at": 1789019337,
                "unbind_reason": "用户主动解绑"
            }
        ]
    }
}
```

## action=logout

**请求（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhU4D/kF50KElhQyzH5eq6idUIiniPbUO5hTmPYJJP5dCv8r/SRg0BjHBc8GvaMpWXIuHAOhnBeo+VkuDoGJ0Hgvid0vIZ0useCv1ej/96viS",
    "sign": "93b1891fc392ad7ff069c831572d17b0c696a1ae584a3bf029f4a22418d1f5cb",
    "t": 1789019337,
    "n": "ae9e0a5c77e831e5"
}
```

**响应（原文）：**

```json
{
    "data": "8JmsB9ktBBOm3/A2F7NnhdDNUkX35pH8tJaU6LkqxFnNpDi/9ydhW5Kl/i1XzBkedmabENM3w8iqnIN9OSXSGPg2UpR6diyWewcVcLv0Pgfk+9vWnzQPqkB7hsYufB+P",
    "sign": "80ef09dd2db29a57d77fd390718a9aed7d905f9d8a50dfa1c730609e9f38341a",
    "t": 1789019337,
    "n": "6d5de3418ae52464",
    "code": 0
}
```

**data 解密后（参考）：**

```json
{
    "code": 0,
    "msg": "已退出登录",
    "time": 1789019337,
    "data": {
        "logout": true
    }
}
```

