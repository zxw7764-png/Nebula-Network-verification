// ============================================================================
// Nebula C# SDK · 极简 JSON
// 扁平递归搜索语义：按 key 在整棵树中递归查找首个命中。
// ============================================================================
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text;

namespace Nebula.Sdk
{
    /// <summary>解析结果：object=Dictionary，array=List，标量为 string/long/double/bool/null</summary>
    public static class Json
    {
        public static object? Parse(string text)
        {
            int i = 0;
            return ParseValue(text, ref i);
        }

        private static void SkipWs(string s, ref int i)
        {
            while (i < s.Length && char.IsWhiteSpace(s[i])) i++;
        }

        private static object? ParseValue(string s, ref int i)
        {
            SkipWs(s, ref i);
            if (i >= s.Length) return null;
            char c = s[i];
            if (c == '{')
            {
                var dict = new Dictionary<string, object?>();
                i++;
                SkipWs(s, ref i);
                if (i < s.Length && s[i] == '}') { i++; return dict; }
                while (i < s.Length)
                {
                    SkipWs(s, ref i);
                    var key = ParseValue(s, ref i) as string ?? "";
                    SkipWs(s, ref i);
                    if (i < s.Length && s[i] == ':') i++;
                    var val = ParseValue(s, ref i);
                    dict[key] = val;
                    SkipWs(s, ref i);
                    if (i < s.Length && s[i] == ',') { i++; continue; }
                    if (i < s.Length && s[i] == '}') { i++; break; }
                    break;
                }
                return dict;
            }
            if (c == '[')
            {
                var list = new List<object?>();
                i++;
                SkipWs(s, ref i);
                if (i < s.Length && s[i] == ']') { i++; return list; }
                while (i < s.Length)
                {
                    var val = ParseValue(s, ref i);
                    list.Add(val);
                    SkipWs(s, ref i);
                    if (i < s.Length && s[i] == ',') { i++; continue; }
                    if (i < s.Length && s[i] == ']') { i++; break; }
                    break;
                }
                return list;
            }
            if (c == '"')
            {
                return ParseString(s, ref i);
            }
            if (i + 4 <= s.Length && s.Substring(i, 4) == "true") { i += 4; return true; }
            if (i + 5 <= s.Length && s.Substring(i, 5) == "false") { i += 5; return false; }
            if (i + 4 <= s.Length && s.Substring(i, 4) == "null") { i += 4; return null; }
            // 数字
            int start = i;
            while (i < s.Length && (char.IsDigit(s[i]) || s[i] == '-' || s[i] == '+' ||
                                    s[i] == '.' || s[i] == 'e' || s[i] == 'E')) i++;
            var num = s.Substring(start, i - start);
            if (long.TryParse(num, NumberStyles.Integer, CultureInfo.InvariantCulture, out var l))
                return l;
            if (double.TryParse(num, NumberStyles.Float, CultureInfo.InvariantCulture, out var d))
                return d;
            return null;
        }

        private static string ParseString(string s, ref int i)
        {
            var sb = new StringBuilder();
            i++; // 跳过开引号
            while (i < s.Length && s[i] != '"')
            {
                if (s[i] == '\\' && i + 1 < s.Length)
                {
                    i++;
                    char e = s[i];
                    switch (e)
                    {
                        case 'n': sb.Append('\n'); break;
                        case 't': sb.Append('\t'); break;
                        case 'r': sb.Append('\r'); break;
                        case 'b': sb.Append('\b'); break;
                        case 'f': sb.Append('\f'); break;
                        case 'u':
                            if (i + 4 < s.Length &&
                                int.TryParse(s.Substring(i + 1, 4), NumberStyles.HexNumber,
                                             CultureInfo.InvariantCulture, out var cp))
                            {
                                sb.Append((char)cp);
                                i += 4;
                            }
                            break;
                        default: sb.Append(e); break;
                    }
                    i++;
                }
                else
                {
                    sb.Append(s[i]);
                    i++;
                }
            }
            if (i < s.Length) i++; // 跳过闭引号
            return sb.ToString();
        }

        // ── 递归查找助手 ─────────────────────────────────────────────────────
        // 兼容标准语义：传入原始 JSON 字符串时自动先解析。
        private static object? RootOf(object? root) => root is string s ? Parse(s) : root;

        private static IEnumerable<object> Walk(object? node)
        {
            if (node is Dictionary<string, object?> dict)
            {
                yield return dict;
                foreach (var v in dict.Values)
                    foreach (var sub in Walk(v)) yield return sub;
            }
            else if (node is List<object?> list)
            {
                foreach (var v in list)
                    foreach (var sub in Walk(v)) yield return sub;
            }
        }

        /// <summary>递归查找第一个含指定 key 的对象</summary>
        public static Dictionary<string, object?>? FindObject(object? root, string key)
            => Walk(RootOf(root)).OfType<Dictionary<string, object?>>()
                         .FirstOrDefault(d => d.ContainsKey(key) && d[key] is Dictionary<string, object?>)
                     is Dictionary<string, object?> inner
                ? (Dictionary<string, object?>?)inner
                : (Walk(RootOf(root)).OfType<Dictionary<string, object?>>()
                             .FirstOrDefault(d => d.ContainsKey(key) && d[key] is Dictionary<string, object?>)
                     as Dictionary<string, object?>);

        public static string FindString(object? root, string key)
            => Walk(RootOf(root)).OfType<Dictionary<string, object?>>()
                         .Where(d => d.ContainsKey(key))
                         .Select(d => d[key] switch
                         {
                             string s => s,
                             long l => l.ToString(CultureInfo.InvariantCulture),
                             double db => db.ToString(CultureInfo.InvariantCulture),
                             bool b => b ? "true" : "false",
                             _ => "",
                         })
                         .FirstOrDefault(v => !string.IsNullOrEmpty(v)) ?? "";

        public static long FindInt64(object? root, string key, long def = 0)
            => Walk(RootOf(root)).OfType<Dictionary<string, object?>>()
                         .Where(d => d.ContainsKey(key))
                         .Select(d => d[key] switch
                         {
                             long l => l,
                             double db => (long)db,
                             string s when long.TryParse(s, out var v) => v,
                             bool b => b ? 1L : 0L,
                             _ => (long?)null,
                         })
                         .FirstOrDefault(v => v != null) ?? def;

        public static int FindInt(object? root, string key, int def = 0)
            => (int)FindInt64(root, key, def);

        public static bool FindBool(object? root, string key, bool def = false)
            => Walk(RootOf(root)).OfType<Dictionary<string, object?>>()
                         .Where(d => d.ContainsKey(key))
                         .Select(d => d[key] switch
                         {
                             bool b => (bool?)b,
                             long l => l != 0,
                             string s => s == "true" || s == "1",
                             _ => (bool?)null,
                         })
                         .FirstOrDefault(v => v != null) ?? def;

        public static List<Dictionary<string, object?>> FindObjectList(object? root, string key)
        {
            var result = new List<Dictionary<string, object?>>();
            foreach (var dict in Walk(RootOf(root)).OfType<Dictionary<string, object?>>())
            {
                if (dict.TryGetValue(key, out var v) && v is List<object?> list)
                    foreach (var item in list.OfType<Dictionary<string, object?>>())
                        result.Add(item);
            }
            return result;
        }

        public static List<string> FindStrings(object? root, string key)
        {
            var result = new List<string>();
            foreach (var dict in Walk(RootOf(root)).OfType<Dictionary<string, object?>>())
            {
                if (dict.TryGetValue(key, out var v) && v is List<object?> list)
                    foreach (var item in list)
                        if (item is string s) result.Add(s);
            }
            return result;
        }

        // ── 构造助手 ───────────────────────────────────────────────────────
        public static string Quote(string s)
        {
            var sb = new StringBuilder("\"");
            foreach (char c in s)
            {
                switch (c)
                {
                    case '"': sb.Append("\\\""); break;
                    case '\\': sb.Append("\\\\"); break;
                    case '\n': sb.Append("\\n"); break;
                    case '\r': sb.Append("\\r"); break;
                    case '\t': sb.Append("\\t"); break;
                    default:
                        if (c < 0x20) sb.Append("\\u").Append(((int)c).ToString("x4"));
                        else sb.Append(c);
                        break;
                }
            }
            sb.Append('"');
            return sb.ToString();
        }

        public static string Pair(string key, string quotedValue) => Quote(key) + ":" + quotedValue;
        public static string Number(long v) => v.ToString(CultureInfo.InvariantCulture);
        public static string Boolean(bool v) => v ? "true" : "false";
    }
}
