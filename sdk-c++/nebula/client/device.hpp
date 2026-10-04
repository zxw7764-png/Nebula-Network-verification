#pragma once
// ============================================================================
// Nebula SDK · 设备身份（机器码 / 设备名 / 硬件指纹）
// ----------------------------------------------------------------------------
// 三者用途不同，别混淆：
//   machine_id  客户端自算的字符串，是「设备身份主键」。改一字节就是"新设备"，
//               所以它必须**持久化**（由接入方保存并在构造时传入），否则每次启动
//               都会变成新设备、把设备名额吃光。
//   device_name 真实电脑主机名（GetComputerNameW），仅用于后台展示与虚拟化关键词识别。
//   device_fp   多硬件组件特征串（WMI 序列号哈希 + 主网卡裸 MAC），用于后台做
//               加权指纹校验、机器码伪造识别与模拟器/虚拟机判定。见 docs/API.md 2.14。
//               **懒采集**（首次登录时一次），采集失败不影响登录。
// ============================================================================

#include "../core/crypto.hpp"
#include "../core/json.hpp"

namespace nebula {
namespace device {

/** 生成随机机器码（16 位 hex）；随机源不可用时返回空串 */
NEBULA_MUST_CHECK inline std::string createMachineId() {
    return crypto::randomHex(8);
}

/** 真实电脑主机名（取不到时回落 "Windows-PC"） */
NEBULA_MUST_CHECK inline std::string computerName() {
    wchar_t buffer[MAX_COMPUTERNAME_LENGTH + 1] = {};
    DWORD len = MAX_COMPUTERNAME_LENGTH + 1;
    if (!::GetComputerNameW(buffer, &len) || len == 0) return "Windows-PC";
    const std::string name = toUtf8(buffer, (int)len);
    return name.empty() ? std::string("Windows-PC") : name;
}

/** 主网卡 MAC（12 位小写裸 hex；找不到返回空串）——服务端用 OUI 识别虚拟机网卡 */
NEBULA_MUST_CHECK inline std::string primaryMac() {
    ULONG size = 0;
    if (::GetAdaptersInfo(nullptr, &size) != ERROR_BUFFER_OVERFLOW || size == 0) return {};
    std::vector<char> buffer(size);
    PIP_ADAPTER_INFO adapter = reinterpret_cast<PIP_ADAPTER_INFO>(buffer.data());
    if (::GetAdaptersInfo(adapter, &size) != NO_ERROR) return {};

    for (; adapter; adapter = adapter->Next) {
        if (adapter->AddressLength != 6) continue;
        char hex[13] = {};
        for (int i = 0; i < 6; ++i) ::sprintf_s(hex + i * 2, 3, "%02x", adapter->Address[i]);
        if (::strcmp(hex, "000000000000") == 0) continue;
        return hex;
    }
    return {};
}

namespace detail {

/** WMI 单属性查询（失败 / 无结果返回空串，不影响登录） */
NEBULA_MUST_CHECK inline std::string wmiQuery(const wchar_t* query, const wchar_t* property) {
    std::string out;

    const HRESULT init = ::CoInitializeEx(nullptr, COINIT_MULTITHREADED);
    // RPC_E_CHANGED_MODE：本线程已是 STA，同样可用（无需也不能再次 Uninitialize）
    const bool shouldUninitialize = SUCCEEDED(init);
    if (FAILED(init) && init != RPC_E_CHANGED_MODE) return out;

    IWbemLocator* locator = nullptr;
    IWbemServices* service = nullptr;
    IEnumWbemClassObject* enumerator = nullptr;
    IWbemClassObject* object = nullptr;
    BSTR ns   = ::SysAllocString(L"ROOT\\CIMV2");
    BSTR lang = ::SysAllocString(L"WQL");
    BSTR wql  = ::SysAllocString(query);

    do {
        if (FAILED(::CoCreateInstance(CLSID_WbemLocator, nullptr, CLSCTX_INPROC_SERVER,
                                      IID_IWbemLocator, (void**)&locator)) || !locator) {
            break;
        }
        if (FAILED(locator->ConnectServer(ns, nullptr, nullptr, nullptr, 0, nullptr, nullptr, &service))
            || !service) {
            break;
        }
        // 代理鉴权设置失败只影响性能，不影响查询（权限受限环境常见），显式忽略返回值
        NEBULA_UNUSED(::CoSetProxyBlanket(service, RPC_C_AUTHN_WINNT, RPC_C_AUTHZ_NONE, nullptr,
                                          RPC_C_AUTHN_LEVEL_CALL, RPC_C_IMP_LEVEL_IMPERSONATE,
                                          nullptr, EOAC_NONE));
        if (FAILED(service->ExecQuery(lang, wql,
                                      WBEM_FLAG_FORWARD_ONLY | WBEM_FLAG_RETURN_IMMEDIATELY,
                                      nullptr, &enumerator)) || !enumerator) {
            break;
        }
        ULONG returned = 0;
        if (FAILED(enumerator->Next(WBEM_INFINITE, 1, &object, &returned)) || returned == 0 || !object) {
            break;
        }
        VARIANT value;
        ::VariantInit(&value);
        if (SUCCEEDED(object->Get(property, 0, &value, nullptr, nullptr))
            && value.vt == VT_BSTR && value.bstrVal) {
            out = toUtf8(value.bstrVal);
        }
        ::VariantClear(&value);
    } while (false);

    if (object)     object->Release();
    if (enumerator) enumerator->Release();
    if (service)    service->Release();
    if (locator)    locator->Release();
    ::SysFreeString(ns);
    ::SysFreeString(lang);
    ::SysFreeString(wql);
    if (shouldUninitialize) ::CoUninitialize();
    return out;
}

/** 指纹组件哈希：MD5 原始值 → 前 16 位 hex（服务端只做等值/相似度比较） */
NEBULA_MUST_CHECK inline std::string fingerprintHash(const std::string& raw) {
    const std::string digest = crypto::md5(raw);
    if (digest.size() != 16) return {};
    return bytesToHex(digest).substr(0, 16);
}

/** 过滤 OEM 占位值（"Default string" 之类会让不同机器得到相同组件） */
NEBULA_MUST_CHECK inline bool fingerprintValueOk(const std::string& value) {
    if (value.size() < 4) return false;
    const std::string lower = toLowerAscii(value);
    static const char* kPlaceholders[] = {
        "default", "to be filled", "not specified", "system serial", "chassis", "unknown",
    };
    for (size_t i = 0; i < sizeof(kPlaceholders) / sizeof(kPlaceholders[0]); ++i) {
        if (lower.find(kPlaceholders[i]) != std::string::npos) return false;
    }
    return lower != "none" && lower != "0123456789" && lower != "0000000000";
}

} // namespace detail

/**
 * 采集设备指纹 JSON；一个有效组件都没有时返回空串（此时登录请求不带 device_fp 字段）。
 * 采集项与服务端 docs/API.md 2.14 的组件权重表一致：board / cpu / disk / bios / mac / gpu。
 */
NEBULA_MUST_CHECK inline std::string collectFingerprintJson() {
    const std::string board = detail::wmiQuery(L"SELECT SerialNumber FROM Win32_BaseBoard", L"SerialNumber");
    const std::string cpu   = detail::wmiQuery(L"SELECT ProcessorId FROM Win32_Processor", L"ProcessorId");
    const std::string disk  = detail::wmiQuery(L"SELECT SerialNumber FROM Win32_DiskDrive", L"SerialNumber");
    const std::string bios  = detail::wmiQuery(L"SELECT SerialNumber FROM Win32_BIOS", L"SerialNumber");
    const std::string gpu   = detail::wmiQuery(L"SELECT Name FROM Win32_VideoController", L"Name");
    const std::string mac   = primaryMac();

    std::string json = "{";
    const auto add = [&json](const char* key, const std::string& value) {
        if (value.empty()) return;
        if (json.size() > 1) json += ",";
        json += nebula::json::pair(key, nebula::json::quote(value));
    };

    if (detail::fingerprintValueOk(board)) add("board", detail::fingerprintHash(board));
    if (detail::fingerprintValueOk(cpu))   add("cpu",   detail::fingerprintHash(cpu));
    if (detail::fingerprintValueOk(disk))  add("disk",  detail::fingerprintHash(disk));
    if (detail::fingerprintValueOk(bios))  add("bios",  detail::fingerprintHash(bios));
    add("mac", mac);                       // 裸 MAC：服务端据此做虚拟机 OUI 识别
    if (detail::fingerprintValueOk(gpu))   add("gpu",   detail::fingerprintHash(gpu));

    json += "}";
    return json.size() > 2 ? json : std::string();
}

} // namespace device
} // namespace nebula
