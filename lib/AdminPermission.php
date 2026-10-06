<?php
/**
 * 管理员 RBAC 权限内核
 * ==================================================================
 * 背景（P0-01）：
 *   修复前管理端只在入口处判断了「只读角色 role=3 不能写」，
 *   role=2（操作员）与 role=1（超管）在业务上完全等同，
 *   55 个 handler 里几乎没有再检查角色。
 *   于是一个「操作员」账号可以直接调用 setting_save 改安全配置、
 *   调 card_generate 印卡、调 agent_recharge_save 发充值卡 —— 属于权限提升。
 *
 * 设计原则：
 *   1. role 1（超管）在 require() 里直接放行，不查矩阵。
 *      目的是「即使矩阵漏配了某个权限点，也不会把超管锁在门外」，
 *      保证向后兼容与可恢复性。
 *   2. role 2 / 3 的权限来源（按优先级）：
 *      ① nb_admins.permissions 自定义清单（超管逐项勾选，非 NULL 即生效）；
 *      ② ROLE_MATRIX 角色默认矩阵（清单为 NULL 时，老账号行为不变）。
 *   3. admin.manage（管理员账号与权限配置）为超管专属硬权限：
 *      不在配置目录中，且即使出现在自定义清单里也不生效。
 *   4. 入口（index.php）按 ACTION_PERM 表对每个 action 统一校验，
 *      handler 无需各自判断；未登记权限点的 action 一律拒绝（默认拒绝）。
 *
 * 权限点命名：<域>.<动作>，域/动作均小写，点号分隔。
 * 新增 handler 时务必同步在 ACTION_PERM 里登记，否则该接口会被拒绝访问。
 */
final class AdminPermission
{
    // ------------------------------------------------------------------
    // 权限点常量
    // ------------------------------------------------------------------
    // 用户
    public const USER_READ    = 'user.read';
    public const USER_EDIT    = 'user.edit';
    public const USER_DELETE  = 'user.delete';
    public const USER_IMPORT  = 'user.import';

    // 卡密 / 发货
    public const CARD_READ     = 'card.read';
    public const CARD_EXPORT   = 'card.export';
    public const CARD_GENERATE = 'card.generate';
    public const CARD_VOID     = 'card.void';

    // 代理 / 资金
    public const AGENT_READ          = 'agent.read';
    public const AGENT_EDIT          = 'agent.edit';
    public const AGENT_GRANT         = 'agent.grant';
    public const AGENT_RECHARGE_CODE = 'agent.recharge_code';

    // 设备 / 会话
    public const DEVICE_READ   = 'device.read';
    public const DEVICE_MANAGE = 'device.manage';
    public const DEVICE_BAN    = 'device.ban';
    public const SESSION_KICK  = 'session.kick';

    // 设置（P1-10 分级）
    public const SETTINGS_SITE     = 'settings.site';
    public const SETTINGS_BUSINESS = 'settings.business';
    public const SETTINGS_SECURITY = 'settings.security';
    public const SETTINGS_INFRA    = 'settings.infra';

    // 其他
    public const AUDIT_READ     = 'audit.read';
    public const ADMIN_MANAGE   = 'admin.manage';
    public const CONTENT_MANAGE = 'content.manage';

    // Runtime Security（§56）
    public const RT_SECURITY_VIEW    = 'rt_security.view';
    public const RT_SECURITY_EVENTS = 'rt_security.events';
    public const RT_POLICY_READ     = 'rt_security.policy.read';
    public const RT_POLICY_WRITE    = 'rt_security.policy.write';
    public const RT_SESSION_BLOCK   = 'rt_security.session.block';
    public const RT_DEVICE_BLOCK    = 'rt_security.device.block';

    // ------------------------------------------------------------------
    // action -> 权限点 映射
    // ------------------------------------------------------------------
    /**
     * 管理端每个 action 所需的权限点。
     * 「只读」类 action 映射到对应域的 .read；`profile` / `logout` 等
     * 与自身账号相关的动作不需要额外权限（任何已登录管理员都能做）。
     */
    private const ACTION_PERM = [
        // ---- 无需额外权限（任何已登录管理员） ----
        'login'                 => null,
        'captcha'               => null,
        'ping'                  => null,
        'logout'                => null,
        'profile'               => null,

        // ---- 总览 / 统计（只读） ----
        'dashboard'             => self::USER_READ,
        'bigscreen'             => self::USER_READ,
        'analytics'             => self::USER_READ,
        'stat_overview'         => self::USER_READ,

        // ---- 用户 ----
        'user_list'             => self::USER_READ,
        'user_detail'           => self::USER_READ,
        'user_risk'             => self::USER_READ,
        'user_export'           => self::USER_READ,
        'user_save'             => self::USER_EDIT,
        'user_batch_op'         => self::USER_EDIT,
        'user_kick'             => self::USER_EDIT,
        'user_import'           => self::USER_IMPORT,
        'user_delete'           => self::USER_DELETE,

        // ---- 卡密 ----
        'card_list'             => self::CARD_READ,
        'card_detail'           => self::CARD_READ,
        'card_batch_list'       => self::CARD_READ,
        'card_export'           => self::CARD_EXPORT,
        'card_generate'         => self::CARD_GENERATE,
        'card_update'           => self::CARD_GENERATE,
        'shop_goods_options'    => self::CARD_GENERATE,
        'card_void'             => self::CARD_VOID,
        'card_batch_op'         => self::CARD_VOID,
        'shop_cards_list'       => self::CARD_READ,
        'shop_card_delete'      => self::CARD_VOID,

        // ---- 发卡网订单 ----
        'shop_order_list'       => self::CARD_READ,
        'shop_order_op'         => self::CARD_GENERATE,
        // 发卡网配置独立页（运营分区）：shop_* 全归业务档，仅超管
        'shop_setting_save'     => self::SETTINGS_BUSINESS,
        // 发卡商品（上架/售价/挂卡规格/商品陈列）：与发卡配置同档，挂错规格=发错卡
        'shop_goods_list'       => self::SETTINGS_BUSINESS,
        'shop_goods_save'       => self::SETTINGS_BUSINESS,
        'shop_goods_upload'     => self::SETTINGS_BUSINESS,
        'shop_goods_import'     => self::SETTINGS_BUSINESS,
        'shop_goods_delete'     => self::SETTINGS_BUSINESS,
        'shop_goods_toggle'     => self::SETTINGS_BUSINESS,

        // ---- 代理 / 资金 ----
        'agent_list'            => self::AGENT_READ,
        'agent_detail'          => self::AGENT_READ,
        'agent_code_list'       => self::AGENT_READ,
        'agent_recharge_list'   => self::AGENT_READ,
        'agent_save'            => self::AGENT_EDIT,
        'agent_code_save'       => self::AGENT_EDIT,
        'agent_recharge_save'   => self::AGENT_RECHARGE_CODE,

        // ---- 设备 / 会话 ----
        'device_list'           => self::DEVICE_READ,
        'device_ban_list'       => self::DEVICE_READ,
        'session_list'          => self::DEVICE_READ,
        'device_unbind'         => self::DEVICE_MANAGE,
        'device_unban'          => self::DEVICE_MANAGE,
        'device_ban'            => self::DEVICE_BAN,
        'session_kick'          => self::SESSION_KICK,

        // ---- 官网互动 / 运营内容 ----
        'notice_list'           => self::CONTENT_MANAGE,
        'notice_save'           => self::CONTENT_MANAGE,
        'version_list'          => self::CONTENT_MANAGE,
        'version_save'          => self::CONTENT_MANAGE,
        'version_batch'         => self::CONTENT_MANAGE,
        'group_list'            => self::CONTENT_MANAGE,
        'group_save'            => self::CONTENT_MANAGE,
        'group_batch'           => self::CONTENT_MANAGE,
        'message_list'          => self::CONTENT_MANAGE,
        'message_op'            => self::CONTENT_MANAGE,
        'feedback_list'         => self::CONTENT_MANAGE,
        'feedback_reply'        => self::CONTENT_MANAGE,
        'plan_list'             => self::CONTENT_MANAGE,
        'plan_save'             => self::CONTENT_MANAGE,
        'seller_list'           => self::CONTENT_MANAGE,
        'seller_save'           => self::CONTENT_MANAGE,
        'screenshot_list'       => self::CONTENT_MANAGE,
        'screenshot_save'       => self::CONTENT_MANAGE,
        // 官网小游戏排行榜（查看 / 删除）
        'game_list'             => self::CONTENT_MANAGE,
        'game_del'              => self::CONTENT_MANAGE,
        // 界面模板管理（官网 + 发卡网换肤，内容运营子页）
        'template_list'         => self::SETTINGS_SITE,
        'template_save'         => self::SETTINGS_SITE,
        // 模板布局与自定义区块（官网模板 Layout 顺序 / sections 区块文件读写）
        'tpl_sections_get'      => self::SETTINGS_SITE,
        'tpl_sections_save'     => self::SETTINGS_SITE,
        'shop_sw_get'           => self::SETTINGS_SITE,
        'shop_sw_save'          => self::SETTINGS_SITE,

        // ---- 日志 / 审计 ----
        'log_list'              => self::AUDIT_READ,
        'audit_list'            => self::AUDIT_READ,
        'audit_detail'          => self::AUDIT_READ,
        'sec_report'            => self::AUDIT_READ,

        // ---- 管理员账号管理（超管专属：admin.manage 即使进入自定义清单也不生效） ----
        'admin_list'            => self::ADMIN_MANAGE,
        'admin_save'            => self::ADMIN_MANAGE,

        // ---- 设置（入口只校验「有没有改设置的资格」，
        //      具体分档在 setting_save.php 内按档校验） ----
        'setting_get'           => self::SETTINGS_SITE,
        'setting_save'          => self::SETTINGS_SITE,
        // 系统维护（健康巡检 / 数据备份）与「系统设置 → 系统」同档
        'system_maintenance'    => self::SETTINGS_INFRA,

        // ---- 离线宽限密钥管理：换钥影响所有客户端离线票据，仅超管档 ----
        'grace_rotate_keys'     => self::SETTINGS_SECURITY,
        'resp_sign_rotate_keys' => self::SETTINGS_SECURITY,

        // ---- 安全自检（数据概览页卡片）：只读扫描，按「安全设置」档 ----
        'security_check'        => self::SETTINGS_SECURITY,

        // ---- 软件管理（多软件网络验证），仅超管档 ----
        'software_list'         => self::SETTINGS_BUSINESS,
        'software_save'         => self::SETTINGS_BUSINESS,
        'software_delete'       => self::SETTINGS_BUSINESS,
        'software_batch'        => self::SETTINGS_BUSINESS,
        // 软件官网内容读写（编辑入口在「内容运营 → 官网内容」）：属站点展示档
        'software_web_get'      => self::SETTINGS_SITE,
        'software_web_save'     => self::SETTINGS_SITE,
        // 官网图片上传（背景图等，官网内容页用）：属站点展示档
        'web_upload'            => self::SETTINGS_SITE,

        // ---- 文件安全（完整性基准 / 挂马扫描 / 受控文件操作），仅超管 ----
        'files_integrity'       => self::SETTINGS_BUSINESS,
        'files_scan'            => self::SETTINGS_BUSINESS,
        'file_view'             => self::SETTINGS_BUSINESS,
        'file_delete'           => self::SETTINGS_BUSINESS,

        // ---- 系统更新（对接 update-system 在线版本更新） ----
        'system_update_check'   => self::SETTINGS_INFRA,
        'system_update_save'    => self::SETTINGS_INFRA,
        'system_update_do'      => self::SETTINGS_INFRA,
        'license_manage'        => self::SETTINGS_INFRA,

        // ---- Runtime Security（§37 §56） ----
        'rt_overview'           => self::RT_SECURITY_VIEW,
        'rt_event_list'         => self::RT_SECURITY_EVENTS,
        'rt_event_detail'       => self::RT_SECURITY_EVENTS,
        'rt_event_handle'       => self::RT_SECURITY_EVENTS,
        'rt_event_batch'        => self::RT_SECURITY_EVENTS,
        'rt_policy_list'        => self::RT_POLICY_READ,
        'rt_policy_detail'      => self::RT_POLICY_READ,
        'rt_policy_save'        => self::RT_POLICY_WRITE,
        'rt_policy_delete'      => self::RT_POLICY_WRITE,
        'rt_risk_devices'       => self::RT_SECURITY_VIEW,
        'rt_risk_sessions'      => self::RT_SECURITY_VIEW,
        'rt_session_unblock'    => self::RT_SESSION_BLOCK,
        'rt_device_unblock'     => self::RT_DEVICE_BLOCK,
    ];

    // ------------------------------------------------------------------
    // 角色 -> 权限点矩阵
    // ------------------------------------------------------------------
    /**
     * role 1（超管）：放行一切（见 require() 的短路逻辑，此处不列全）。
     *
     * role 2（操作员）：日常运营。可看用户/卡密/代理/设备，可改用户资料、
     *   举报处理、客服回复、内容上下架；可改【站点展示设置】（settings.site，
     *   如站名/公告/客服联系方式，属无害的运营面配置）；
     *   但【不能】发卡密（印钱）、【不能】碰代理额度与余额（资金）、
     *   【不能】改业务/安全/基础设施设置、【不能】看审计日志、
     *   【不能】删用户、【不能】封设备、【不能】批量导入、【不能】导出卡密。
     *
     * role 3（只读）：仅 .read 与内容查看，不能有任何写操作。
     */
    private const ROLE_MATRIX = [
        2 => [
            self::USER_READ,
            self::USER_EDIT,

            self::CARD_READ,

            self::AGENT_READ,

            self::DEVICE_READ,
            self::SESSION_KICK,

            self::CONTENT_MANAGE,

            // 站点展示设置：入口 action 是 setting_get/setting_save，
            // 具体分档在 setting_save.php 内按档二次校验。
            self::SETTINGS_SITE,
        ],

        3 => [
            self::USER_READ,
            self::CARD_READ,
            self::AGENT_READ,
            self::DEVICE_READ,
        ],
    ];

    /** 角色名称（与 AdminAuth::roleName 保持一致，避免两处漂移） */
    private const ROLE_NAMES = [1 => '超级管理员', 2 => '操作员', 3 => '只读'];

    // ------------------------------------------------------------------
    // 权限点目录（超管配置界面的分组与中文名）
    // ------------------------------------------------------------------
    /**
     * admin.manage（管理员账号与权限配置）刻意不在此目录中：
     * 它是超管专属硬权限 —— 即使被写进某账号的自定义清单也不生效
     * （见 allows()），否则被授权的管理员就能自行改权限，权限体系失守。
     */
    private const PERM_CATALOG = [
        '用户与设备' => [
            self::USER_READ    => '查看用户与统计',
            self::USER_EDIT    => '编辑用户 / 踢下线',
            self::USER_IMPORT  => '批量导入用户',
            self::USER_DELETE  => '删除用户',
            self::DEVICE_READ  => '查看设备与会话',
            self::DEVICE_MANAGE => '解绑 / 解封设备',
            self::DEVICE_BAN   => '拉黑设备',
            self::SESSION_KICK => '强制下线会话',
        ],
        '卡密与交易' => [
            self::CARD_READ    => '查看卡密与订单',
            self::CARD_GENERATE => '生成卡密',
            self::CARD_VOID    => '作废卡密',
            self::CARD_EXPORT  => '导出卡密',
        ],
        '代理' => [
            self::AGENT_READ   => '查看代理与激活码',
            self::AGENT_EDIT   => '编辑代理',
            self::AGENT_GRANT  => '调整代理额度',
            self::AGENT_RECHARGE_CODE => '生成代理充值卡',
        ],
        '内容运营' => [
            self::CONTENT_MANAGE => '公告 / 版本 / 留言 / 反馈 / 商家等',
        ],
        '设置' => [
            self::SETTINGS_SITE     => '站点展示设置',
            self::SETTINGS_BUSINESS => '业务设置（软件管理 / 发卡 / 文件安全）',
            self::SETTINGS_SECURITY => '安全设置',
            self::SETTINGS_INFRA    => '系统维护与更新',
        ],
        '日志审计' => [
            self::AUDIT_READ => '查看操作与审计日志',
        ],
        '运行时安全' => [
            self::RT_SECURITY_VIEW    => '查看运行时安全概览',
            self::RT_SECURITY_EVENTS  => '查看/处理安全事件',
            self::RT_POLICY_READ      => '查看运行时策略',
            self::RT_POLICY_WRITE     => '编辑运行时策略',
            self::RT_SESSION_BLOCK    => '阻断/解除会话',
            self::RT_DEVICE_BLOCK     => '阻断/解除设备',
        ],
    ];

    // ------------------------------------------------------------------
    // 查看类 action 白名单
    // ------------------------------------------------------------------
    /**
     * 「能看到功能展示、不能实际操作」：下列查看类 action 对所有已登录
     * 管理员放行（数据展示层），写操作仍按超管勾选的权限清单拦截。
     *
     * 刻意不放行的敏感查看接口（即使它们语义上也是「查看」）：
     *   · software_list        —— 软件清单与 app_key（业务识别标识）
     *   · setting_get          —— 含支付参数 / 安全配置 / 缓存连接信息
     *   · admin_list           —— 管理员清单与权限配置（admin.manage 硬权限）
     *   · user_export / card_export —— 数据导出（外带），导出权限单独控制
     *   · shop_goods_list      —— 发卡商品与挂卡规格（业务档配置）
     *   · template_list / tpl_sections_get / shop_sw_get / software_web_get
     *                          —— 站点展示档配置
     *   · files_* / system_update_* —— 安全工具与基础设施档
     * 未放行的页面由前端统一渲染「未授权」空态（见 router.js GUARD_OF_PAGE）。
     */
    private const VIEW_ACTIONS = [
        // 总览 / 统计
        'dashboard', 'bigscreen', 'analytics', 'stat_overview',
        // 用户与设备（查看）
        'user_list', 'user_detail', 'user_risk', 'group_list',
        'device_list', 'device_ban_list', 'session_list',
        // 卡密与交易（查看）
        'card_list', 'card_detail', 'card_batch_list',
        'shop_cards_list', 'shop_order_list',
        // 代理（查看）
        'agent_list', 'agent_detail', 'agent_code_list', 'agent_recharge_list',
        // 内容运营（查看）
        'notice_list', 'version_list', 'message_list', 'feedback_list',
        'plan_list', 'seller_list', 'screenshot_list', 'game_list',
        // Runtime Security（查看）
        'rt_overview', 'rt_event_list', 'rt_event_detail', 'rt_policy_list', 'rt_policy_detail',
        'rt_risk_devices', 'rt_risk_sessions',
        // 注意：log_list / audit_list / audit_detail / sec_report 故意不进白名单
        // —— 它们声明 AUDIT_READ 权限点，操作员角色默认没有，必须按勾选放行。
        // 放进 VIEW_ACTIONS 会绕过 AUDIT_READ（2026-09-30 审计发现的逻辑矛盾，已修）。
    ];

    // ------------------------------------------------------------------
    // 校验
    // ------------------------------------------------------------------

    /**
     * 校验管理员是否具备某权限点，不具备则直接输出错误并终止。
     * role 1 无条件放行（见类注释「设计原则 1」）。
     */
    public static function require(array $admin, string $permission): void
    {
        if (self::allows($admin, $permission)) {
            return;
        }
        Response::error(1004, self::denyMessage($permission));
    }

    /** 是否具备权限（不抛错、不输出，供内部与自检脚本使用） */
    public static function allows(array $admin, string $permission): bool
    {
        $role = (int) ($admin['role'] ?? 0);

        // 超管：无条件放行，保证矩阵漏配也不会锁死系统
        if ($role === 1) {
            return true;
        }
        if ($role !== 2 && $role !== 3) {
            return false; // 未知角色一律拒绝
        }
        // null / '' 表示「无需权限」，任何已登录管理员都可访问
        if ($permission === '' || $permission === null) {
            return true;
        }
        // 硬规则：管理员账号与权限配置仅超管可操作。
        // 即使被写进自定义权限清单也不放行，防止被授权者自行扩权。
        if ($permission === self::ADMIN_MANAGE) {
            return false;
        }

        // 自定义权限清单（超管逐项勾选）：非 NULL 时以它为准，
        // 否则回退到角色默认矩阵（老账号 permissions=NULL 行为不变）
        $custom = self::customPermissionsOf($admin);
        if ($custom !== null) {
            return in_array($permission, $custom, true);
        }
        return in_array($permission, self::ROLE_MATRIX[$role] ?? [], true);
    }

    /**
     * 该管理员的自定义权限清单（解析 nb_admins.permissions JSON 列）。
     * 返回 null = 未配置（列 NULL / 漏跑迁移），走角色默认矩阵。
     */
    public static function customPermissionsOf(array $admin): ?array
    {
        $raw = $admin['permissions'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $list = json_decode((string) $raw, true);
        if (!is_array($list)) {
            return null;   // 数据损坏按未配置处理，比锁死更安全
        }
        return array_values(array_map('strval', $list));
    }

    /** 权限点目录（分组 => [权限点 => 中文名]），供超管配置界面渲染 */
    public static function catalog(): array
    {
        return self::PERM_CATALOG;
    }

    /** 目录中登记的全部权限点（校验保存的自定义清单用，防注入任意键） */
    public static function catalogPermissions(): array
    {
        $out = [];
        foreach (self::PERM_CATALOG as $perms) {
            foreach ($perms as $p => $_) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * 按 action 校验（入口 index.php 使用）
     * 未登记权限点的 action 一律拒绝 —— 默认拒绝，避免新增接口忘记配权限。
     */
    public static function requireAction(array $admin, string $action): void
    {
        if (!array_key_exists($action, self::ACTION_PERM)) {
            // 未登记：拒绝。这条日志是排查「新接口 403」的第一现场。
            Logger::log('admin_rbac', 0, "未登记权限点的 action: {$action}", [
                'admin_id' => (int) ($admin['id'] ?? 0),
            ]);
            Response::error(1004, '该功能未配置访问权限，请联系超级管理员');
        }

        $perm = self::ACTION_PERM[$action];
        if ($perm === null) {
            return; // 无需权限
        }
        // 查看类白名单：登录管理员一律放行（功能展示可见，操作才按勾选拦截）
        if (in_array($action, self::VIEW_ACTIONS, true)) {
            return;
        }
        self::require($admin, $perm);
    }

    /** 某 action 声明的权限点（未登记返回 false，null 权限点返回 null） */
    public static function actionPermission(string $action)
    {
        return array_key_exists($action, self::ACTION_PERM)
            ? self::ACTION_PERM[$action]
            : false;
    }

    /** 已登记的全部 action 名 */
    public static function registeredActions(): array
    {
        return array_keys(self::ACTION_PERM);
    }

    /**
     * 当前管理员持有的全部权限点（供前端渲染菜单 / 隐藏按钮）
     * 超管返回 '*' 通配，前端据此显示全部入口。
     * 自定义清单（超管逐项配置）原样下发；未配置则回退角色默认矩阵。
     */
    public static function permissionsOf(array $admin)
    {
        $role = (int) ($admin['role'] ?? 0);
        if ($role === 1) {
            return '*';
        }
        $custom = self::customPermissionsOf($admin);
        if ($custom !== null) {
            return $custom;
        }
        return self::ROLE_MATRIX[$role] ?? [];
    }

    /** 给前端的权限摘要（挂在 admin 信息里下发） */
    public static function publicInfo(array $admin): array
    {
        return [
            'role'        => (int) ($admin['role'] ?? 0),
            'role_text'   => self::ROLE_NAMES[(int) ($admin['role'] ?? 0)] ?? '未知',
            'permissions' => self::permissionsOf($admin),
        ];
    }

    /** 拒绝时的提示文案 */
    private static function denyMessage(string $permission): string
    {
        $names = [
            self::USER_DELETE        => '删除用户',
            self::USER_IMPORT        => '批量导入用户',
            self::CARD_EXPORT        => '导出卡密',
            self::CARD_GENERATE      => '生成卡密',
            self::CARD_VOID          => '作废卡密',
            self::AGENT_EDIT         => '修改代理档案',
            self::AGENT_RECHARGE_CODE => '生成代理商充值卡',
            self::DEVICE_MANAGE      => '解绑设备',
            self::DEVICE_BAN         => '拉黑设备',
            self::SETTINGS_BUSINESS  => '修改业务设置',
            self::SETTINGS_SECURITY  => '修改安全设置',
            self::SETTINGS_INFRA     => '修改基础设施配置',
            self::AUDIT_READ         => '查看审计日志',
            self::ADMIN_MANAGE       => '管理员管理',
        ];
        $label = $names[$permission] ?? $permission;
        return "当前角色无权执行「{$label}」操作";
    }
}
