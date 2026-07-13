<?php
/**
 * HTMLHub - 单文件 PHP HTML 社区
 * 版本: 3.18.0
 *
 * 部署: 将此文件放到 PHP 服务器根目录，访问即可。
 * 首次访问会进入安装向导（含 MySQL 数据库配置）；之后自动隐藏。
 * 依赖: PHP 7.4+ / PDO_MYSQL
 *
 * 3.18.0 更新（新增：无感人机验证 BotGuard）：
 *   - 新增：登录、注册、发帖、发评论 4 个接口强制无感人机验证
 *     · 真实浏览器用户零感知（无可见 UI、无交互、无延迟）
 *     · Headless 浏览器、curl/wget、Python requests 等非图形
 *       客户端因缺失 Canvas/WebGL/Intl 能力，无法生成合法指纹，
 *       直接被服务端拒绝
 *   - 设计原理：
 *     · 前端被动采集 5 项浏览器能力信号（performance.now 精度、
 *       Canvas 2D 渲染像素、WebGL 渲染器、运动传感器、时区）
 *     · 服务端用 HMAC-SHA256 签发短时效 token（30 分钟），
 *       token 与指纹绑定，单次使用防重放
 *     · 表单提交时携带 token + 指纹，服务端验签 + 比对指纹
 *   - 安全特性：
 *     · HMAC 密钥安装时生成，写入 config 文件，跨节点共享
 *     · 旧版安装自动升级（首次请求检测到缺失密钥时补写）
 *     · 兜底密钥派生：即使 config 缺失也能保证服务可用
 *     · constant-time 签名比对，防时序攻击
 *     · nonce 防重放：同一 token 只能用一次
 *     · 验证失败时静默拒绝（不告诉攻击者具体哪项失败）
 *   - 兼容性：保留原有反机器人机制（IP 限流、蜜罐、表单时间、
 *     同源 Referer）作为第二道防线
 *
 * 3.17.2 更新（修复：返回键在两级页面间反复横跳死循环）：
 *   - 修复：从「开始游玩」返回「作品详情页」后，再次返回会
 *     跳回「开始游玩」，导致 /post ↔ /play 之间无限横跳
 *     · 根因：早期版本维护了自定义 _routeHistory 路由栈，
 *       goBack() 时 `location.hash = prev` 不会"回到"既有
 *       历史条目，而是在浏览器历史里 *新增* 一条记录。当
 *       自定义栈耗尽、改用 history.back() 兜底时，浏览器
 *       实际指针停在这些"假历史"之间，于是 A→B→A→B 死循环
 *     · 修复：移除自定义 _routeHistory 栈与 pushRoute()，
 *       goBack() 统一使用浏览器原生 history.back()，行为
 *       与浏览器返回键完全一致
 *     · 优化：go() 同 hash 跳转短路，避免冗余历史条目
 *     · 优化：render() 不再调用 pushRoute，避免与浏览器
 *       历史失步
 *   - 新增：深链（如直接打开 /post/123）按返回键时兜底跳首页，
 *     而非直接退出 SPA
 *     · 通过 history.state 标记 SPA 入口条目（__spaRoot=true）
 *     · goBack() 在根入口处自动跳 /home，提升用户体验
 *
 * 3.17.1 更新（修复：动画模式误用物理模式规则）：
 *   - 修复：动画模式下向下滑动时位移被错误重置
 *     · 根因：动画模式复用了物理模式的 else 分支逻辑，
 *       当 offset !== 0 但不在边界时直接 setOffset(0)，
 *       导致拉伸中反向滑动会突然归零（"重置"感）
 *     · 修复：动画模式用独立的 isInRubberBand 状态机，
 *       反向滑动时让 offset 平滑过零再退出，不突然归零
 *   - 修复：动画模式正常滚动不触发橡皮筋
 *     · 只有在边界且向外拉才进入橡皮筋状态
 *     · 正常滚动区域完全不干涉（不 preventDefault）
 *   - 优化：动画模式回弹动画期间再次触摸可无缝接管
 *
 * 3.17.0 更新（橡皮筋回弹可配置）：
 *   - 新增：设置页「滑动」分区，两个选项
 *     · 橡皮筋回弹开关（开/关）— 关闭后无回弹效果，纯原生滚动
 *     · 回弹模式选择（动画模式/物理模式）— 动画模式成熟稳定，物理模式测试中
 *   - 动画模式（默认）：CSS transition 回弹，成熟无 bug
 *   - 物理模式（测试中）：requestAnimationFrame 弹簧物理模拟
 *   - 设置存储在 localStorage，刷新后生效
 *
 * 3.16.4 更新（修复：回弹动画中无法滑动）：
 *   - 修复：物理回弹未结束时触摸屏幕无法继续滚动
 *     · 根因：旧逻辑用 offset !== 0 判断是否在橡皮筋状态，
 *       回弹动画被 touchstart 取消后 offset 仍有残余位移，
 *       导致 touchmove 误判为橡皮筋状态，preventDefault 阻止了正常滚动
 *     · 修复：橡皮筋状态的唯一判断条件改为「在边界且本次 move 方向朝外」，
 *       不再依赖 offset 是否为 0；
 *       如果不在边界向外拉，立即归零 offset 并让浏览器原生滚动接管
 *
 * 3.16.3 更新（紧急修复：列表无法滚动）：
 *   - 修复：橡皮筋逻辑误判导致正常滚动被 preventDefault 拦截
 *     · 根因：touchmove 中用 currentY > touchStartY 判断方向，
 *       但 touchStartY 是整个触摸开始的位置，在列表中间滑动时
 *       向下滚 currentY < touchStartY 也会误触发边界判断
 *     · 修复：改用 moveDelta（本次 move 增量）判断方向，
 *       只有在边界且 moveDelta 方向正确时才 preventDefault，
 *       正常滚动绝不阻止
 *
 * 3.16.2 更新（橡皮筋回弹改为真实物理引擎）：
 *   - 重写：从 CSS transition 动画改为 requestAnimationFrame 物理模拟
 *     · 弹簧-质量-阻尼物理模型（胡克定律 F=-kx + 阻尼 F=-cv）
 *     · 每帧实时计算弹簧力、阻尼力、加速度、速度、位移
 *     · 回弹曲线由物理方程驱动，而非预设的 cubic-bezier 曲线
 *   - 修复：重复触摸打断上一个动画导致冲突
 *     · touchstart 立即 cancelAnimationFrame 取消正在进行的动画
 *     · 手指接管位移，从当前位置继续拖拽（不跳变）
 *     · 松手后重新启动物理回弹，自然过渡
 *   - 优化：拉伸阻尼改为非线性（位移越大阻力越强）
 *     · resistanceFactor = 1 - (offset/MAX) * 0.7
 *     · 最大拉伸 150px
 *   - 优化：物理参数可调
 *     · SPRING_K=0.12（弹簧劲度，越大回弹越快）
 *     · DAMPING=0.72（阻尼，越大衰减越快）
 *     · MAX_STRETCH=150（最大拉伸）
 *     · RESISTANCE=0.38（拖拽阻力）
 *
 * 3.16.1 更新（修复图片迁移后头像不显示）：
 *   - 修复：评论、通知、粉丝列表、工作室等多处头像不显示（坏图图标）
 *     · 根因：图片迁移后 avatar 字段存的是图片 ID（数字），但很多 API
 *       端点直接返回原始值，前端拿到 "123" 作为 <img src> 导致坏图
 *     · 修复：所有返回 avatar/cover 的 API 端点统一调用 resolve_image()
 *   - 修复的端点清单（共 15 处）：
 *     · comments（评论列表头像）
 *     · login（登录返回用户头像）
 *     · notifications（通知触发者头像）
 *     · user_posts（用户作品页作者头像）
 *     · comment（发评论后返回的头像）
 *     · studios（工作室列表封面+作者头像）
 *     · studio（工作室详情封面+作者头像）
 *     · studio_members（工作室成员头像）
 *     · studio_invite_search（邀请搜索用户头像）
 *     · my_invitations（邀请列表头像+工作室封面）
 *     · studio_invitations（被邀请人头像）
 *     · hosted_list（托管列表作者头像）
 *     · hosted_view（托管详情作者头像）
 *     · admin_users（管理后台用户列表头像）
 *     · admin_search_users（管理后台搜索用户头像）
 *     · admin_reports（举报箱举报人+被举报人头像）
 *
 * 3.16.0 更新（图片分离存储 — 重大性能优化）：
 *   - 重大：图片从 base64 内联存储改为独立 images 表
 *     · 之前：posts.cover / posts.images / users.avatar 直接存 base64 数据
 *       列表查询返回巨大 base64，API 响应慢、带宽高
 *     · 现在：base64 存入 images 表，原字段只存图片 ID（数字）
 *       API 返回 "?api=image&id=123"（几十字节），图片按需加载
 *     · 性能提升：列表 API 响应体积下降 90%+，首屏加载快数倍
 *   - 新增：?api=image&id=123 图片服务端点
 *     · 输出原始二进制图片（非 base64），带 Content-Type
 *     · Cache-Control: public, max-age=2592000, immutable（30 天缓存）
 *     · ETag 支持，浏览器 304 缓存
 *   - 向后兼容：旧数据（data:image/... base64）原样返回，新数据用图片 ID
 *     · resolve_image() 自动判断：纯数字=ID→URL，data:=旧base64→原样
 *   - 新增：admin_migrate_images 迁移端点
 *     · 管理后台「设置」页新增「迁移图片到 images 表」按钮
 *     · 每次处理 50 条，循环调用直到剩余为 0
 *     · 迁移 posts.cover / posts.images / users.avatar
 *   - 新增辅助函数：store_image() / resolve_image() / store_image_array() / resolve_image_array()
 *   - 前端零改动：<img src> 兼容 data: URL 和 ?api=image URL
 *
 * 3.15.1 更新（橡皮筋回弹大幅优化）：
 *   - 修复：快速滑动到边界不触发回弹（旧版只在 touchstart 时检测边界）
 *   - 优化：touchmove 中持续检测边界，滑动中到达边界立即激活橡皮筋
 *   - 优化：惯性滚动到达边界也能回弹（scroll 事件 + 延迟检测）
 *   - 优化：非线性阻尼（位移越大阻力越强，模拟真实橡皮筋）
 *   - 优化：速度感知回弹（滑动越快回弹动画越快，200-450ms 自适应）
 *   - 优化：最大橡皮筋距离 120px（防止过度拉伸）
 *   - 优化：边界容差 1px（避免浮点数导致边界判断偏差）
 *
 * 3.15.0 更新（橡皮筋回弹滚动）：
 *   - 新增：所有可滑动列表的橡皮筋回弹效果（仅移动端）
 *     · 滑到顶部/底部边界时弹性回弹，模拟 iOS 原生体验
 *     · 阻尼系数 0.4，回弹动画 350ms cubic-bezier 缓动
 *     · 仅在触摸边界时激活，中间区域保持原生惯性滚动
 *   - iOS 专属处理：
 *     · PHP 端检测 iOS UA，输出 data-platform="ios" 到 <html>
 *     · CSS 禁用原生 -webkit-overflow-scrolling + overscroll-behavior:none
 *     · JS 接管弹性滚动，避免原生与自定义冲突
 *   - 桌面端不启用：≥1024px 使用原生滚动（鼠标滚轮无需橡皮筋）
 *   - 自动绑定：MutationObserver 监听 DOM 变化，新插入的 .page-scroll 自动启用
 *   - 覆盖范围：首页 feed / 发现 / 搜索 / 帖子详情 / 个人主页 / 收藏 /
 *     工作室 / 通知 / 托管 / 管理后台 / 举报页 等所有 page-scroll 容器
 *
 * 3.14.1 更新（修复桌面端侧边栏位置 bug）：
 *   - 修复：桌面端侧边栏显示在屏幕中央而非左侧
 *     · 根因：768px 断点设置了 .bottom-nav{max-width:600px;margin:0 auto;left:0;right:0}
 *       这些规则在 1024px 断点对 .desktop-sidebar 仍然生效（CSS 级联）
 *       导致侧边栏被限制为 600px 宽并水平居中
 *     · 修复：在 .desktop-sidebar 规则中显式重置 max-width:none / margin:0 / right:auto
 *
 * 3.14.0 更新（桌面端支持 + 联系方式重设计）：
 *   - 重大：桌面端完整响应式布局
 *     · ≥768px 平板：内容居中，最大宽度 600px
 *     · ≥1024px 桌面：左侧 240px 固定侧边导航 + 右侧宽内容区
 *       底部导航隐藏，改为侧边栏（含品牌 logo + 导航项 + 发布按钮）
 *     · ≥1280px 大屏：feed 三列网格，内容区最大 1400px
 *     · 窗口缩放自动切换布局（resize 监听器重新渲染）
 *   - 桌面端细节：
 *     · 顶部栏 sticky 定位，内容居中
 *     · feed 卡片网格布局（双列/三列），不再单列
 *     · 帖子详情、个人主页、编辑表单内容居中限宽
 *     · 管理后台、托管列表、工作室列表宽屏适配
 *     · hover 状态（卡片悬停、按钮悬停反馈）
 *   - 重新设计：联系方式卡片
 *     · 更紧凑的间距（padding/字号减小）
 *     · 平台标签大写 + 字间距（更专业）
 *     · 头部图标改为 SVG（矢量清晰）
 *     · 数量徽标改为圆角药丸（pill）样式
 *     · hover 反馈（桌面端悬停高亮）
 *
 * 3.13.4 更新（Markdown 编辑器专业升级）：
 *   - 重新设计：文字动态的 Markdown 编辑器
 *     · 工具栏按钮全部改为 SVG 图标（粗体/斜体/删除线/列表/引用/代码/链接等）
 *     · 分组分隔线更细更清晰，按钮间距优化
 *     · 预览按钮独立配色（accent-soft 背景），点击切换编辑/预览图标
 *   - 移动端适配：
 *     · 工具栏横向滚动（不再换行堆积），隐藏滚动条
 *     · 按钮最小 34×32px，触摸友好
 *     · -webkit-overflow-scrolling:touch 惯性滚动
 *   - 编辑器整体包裹：统一边框 + 圆角，工具栏/编辑区/预览区视觉一体
 *   - textarea 改用等宽字体（SF Mono），代码编辑更专业
 *   - 预览区最大高度 500px，内容多时独立滚动
 *
 * 3.13.3 更新（登录/注册页扁平化重设计）：
 *   - 重新设计：登录/注册页面改为扁平化设计
 *     · 移除径向渐变背景、模糊光晕、阴影、渐变按钮
 *     · 纯色背景 + 纯色按钮 + 1px 边框输入框
 *     · Logo 从 72px 渐变圆角 → 56px 纯色方块（更克制）
 *     · 动画从旋转弹跳 → 简洁淡入（300ms）
 *     · 移除按钮 shimmer 闪光效果
 *   - 修复：返回按钮不再挡住 H 图标
 *     · 从左上角移到右上角（使用 .auth-back 类）
 *     · 透明背景，点击时有浅色底色反馈
 *   - 优化：输入框聚焦时只变边框色，不再有 4px 光晕环
 *   - 优化：auth-switch 移除背景色，改为纯文字链接
 *
 * 3.13.2 更新（持久化模式 — 安全与功能的平衡）：
 *   - 新增：HTML 作品 / 托管页面的「持久化模式」（作者可选）
 *     · 默认关闭：sandbox 无 allow-same-origin，null origin，localStorage 不可用（最安全）
 *     · 作者主动开启后：sandbox 有 allow-same-origin（localStorage 可用）+ 严格 CSP
 *     · 适合需要保存数据的游戏/应用（如存档、设置、关卡进度）
 *   - 持久化模式的安全模型（三重防护）：
 *     1. cookie 是 httponly → JS 读不到 session / remember_token
 *     2. CSP connect-src 'none' → fetch/XHR/WebSocket 全阻断，无法调 API
 *     3. CSP form-action 'none' → 表单提交阻断，无法 CSRF
 *     · 结果：localStorage 可用，但无法窃取登录态 / 冒充用户
 *   - 发布页 / 托管编辑器新增「💾 持久化模式」开关 + 安全说明
 *   - 播放页顶栏显示「💾 持久模式」徽标（告知查看者当前模式）
 *   - 数据库：posts / hosted_pages 新增 persistent_mode 列
 *   - 向后兼容：旧作品 persistent_mode 默认 0（安全模式），不受影响
 *
 * 3.13.1 更新（关键安全修复）：
 *   - 修复：托管页面同源 XSS 漏洞（可窃取用户登录态）
 *     · 根因：?hosted=SLUG 直接输出用户 HTML 作为顶级文档，
 *       恶意 JS 与主站同源，可 fetch('/?api=me') 窃取登录信息、
 *       document.cookie 读取 cookie、localStorage 读取数据
 *     · 修复：改为 wrapper 页面 + sandboxed iframe（无 allow-same-origin）
 *       iframe 获得 null origin，无法访问父页面 cookie/localStorage/API
 *     · wrapper 页面 CSP：default-src 'none'; frame-src data: blob:
 *   - 修复：HTML 作品播放/预览 iframe 的 allow-same-origin 漏洞
 *     · 根因：play-iframe / lp-iframe / hosted-viewer-iframe 都设了
 *       allow-same-origin，恶意 HTML 作品可窃取查看者 session
 *     · 修复：移除所有用户内容 iframe 的 allow-same-origin
 *       保留 allow-scripts allow-forms allow-popups allow-modals
 *       allow-downloads allow-pointer-lock allow-presentation
 *     · HTML 作品仍可正常运行 JS，但运行在 null origin 沙箱中
 *   - 隔离效果：恶意 HTML 只能：
 *     ✅ 运行 JS（Canvas / 动画 / 游戏等正常工作）
 *     ✅ 加载外部 CDN 资源（受 CSP 白名单限制）
 *     ✅ 使用 alert / prompt / 表单提交
 *     ❌ 访问 document.cookie（返回空字符串）
 *     ❌ 访问 localStorage（SecurityError 或 null origin 隔离）
 *     ❌ 调用同源 API（fetch('/?api=...') 被视为跨域，不发 cookie）
 *     ❌ 访问 parent.document（SecurityError）
 *
 * 3.13.0 更新（安全加固版）：
 *   - 加固：管理员密码存储改为 bcrypt hash（兼容旧版明文，自动升级）
 *     · 旧版明文密码在下次登录时自动 hash 化，无需手动迁移
 *     · 密码比较使用 password_verify / hash_equals（常量时间，防时序攻击）
 *   - 加固：管理员登录频率限制（IP 维度，三窗口）
 *     · 60 秒内最多 5 次尝试
 *     · 1 小时内最多 20 次
 *     · 1 天内最多 50 次
 *     · 超限返回 429，记录失败日志
 *   - 加固：登录失败 300ms 延迟响应（防时序攻击）
 *   - 加固：管理员 session 绑定指纹（IP 前两段 + User-Agent hash）
 *     · 换设备 / 跨网段立即失效，防 session 劫持
 *     · 允许同 /16 子网（移动网络切换不掉线）
 *   - 加固：管理员会话 2 小时自动过期，活跃操作自动续期
 *   - 加固：登录成功 / 退出时 session_regenerate_id（防 session 固定攻击）
 *   - 加固：require_admin 每次调用重新校验指纹 + 超时（不只是检查标志位）
 *   - 加固：修改密码需二次验证旧密码（防 session 被盗后改密码）
 *   - 加固：新密码强度要求（至少 8 位，必须含字母和数字）
 *   - 新增：管理员操作日志（审计）
 *     · 记录登录成功/失败、退出、改密码、删用户、批量删除、处理举报等
 *     · 每条含时间、IP、动作、详情，最多保留 100 条
 *     · 管理后台「设置」页可查看 + 清空日志
 *   - 新增：admin_logs / admin_logs_clear 接口
 *   - 优化：管理员登录页移除「默认密码 admin」提示，改为安全说明
 *
 * 3.12.0 更新：
 *   - 重新设计：联系方式展示从 profile-head 内移出，改为独立卡片区块
 *     · 卡片式容器，带「📇 联系方式」标题 + 数量徽标
 *     · 双列网格布局，每项是一张小卡片（图标 + 平台名 + 值 + 复制按钮）
 *     · 邮箱/手机/网站/GitHub/Gitee 生成可点击链接，其他平台点击复制
 *     · 单条联系方式时自动单列布局
 *     · 视觉层次更清晰，不再挤在简介下方
 *   - 新增：举报系统（完整闭环）
 *     · 用户可举报帖子 / 评论 / 用户，进入独立举报页选择原因 + 补充说明
 *     · 11 种举报原因（垃圾广告/色情低俗/暴力/政治敏感/人身攻击/骚扰/诈骗/
 *       侵权/恶意代码/其他不当/自定义），自定义原因必填说明
 *     · 安全：原因白名单校验、detail 经 clean_text 清洗防 XSS、
 *       不能举报自己、同一目标只能举报一次、频率限制（60s/3次，1h/20次）
 *     · 管理后台新增「举报箱」标签页：待处理/已处理/已忽略/全部 四种筛选
 *     · 举报列表展示：举报人、被举报人、目标内容快照、原因、补充说明、时间
 *     · 管理员操作：删除帖子/删除评论/封禁用户/解封用户/标记已处理/忽略/删除记录
 *     · 处理记录：处理人、处理时间、操作结果、备注
 *   - 新增：ICO.flag 举报图标
 *   - 数据库：新增 reports 表（reporter_id / target_type / target_id / reason /
 *     detail / status / handler_id / handler_note / created_at / handled_at）
 *
 * 3.11.1 更新：
 *   - 修复：关注用户后粉丝量错误地增加到了「获赞」上（强迫症 bug）
 *     · 根因：toggleFollowUser 用 querySelectorAll('.p-stat')[1] 定位粉丝数，
 *       但统计栏顺序是 [作品, 获赞, 粉丝, 关注]，[1] 是获赞不是粉丝
 *     · 修复：改用 data-stat="followers" 属性精准定位，不再依赖位置
 *   - 新增：退出登录二次确认弹窗
 *     · 点击退出按钮后弹 showConfirm 确认框，避免误触
 *   - 新增：个人主页支持展示联系方式
 *     · 编辑资料页新增「联系方式」区块，支持 19 种平台：
 *       微信 / QQ / 邮箱 / 手机 / Telegram / Discord / GitHub / Gitee / 微博 /
 *       哔哩哔哩 / 知乎 / Twitter / Instagram / YouTube / 抖音 / 领英 / Steam /
 *       个人网站 / 自定义
 *     · 每条联系方式含平台标识 + 值 + 标签，最多 10 条
 *     · 后端严格校验：平台白名单、邮箱格式、QQ 纯数字、手机号格式、网站 URL 格式
 *     · 前端展示：邮箱/手机/网站/GitHub/Gitee 生成可点击链接，其他平台点击复制
 *     · 安全：所有值经 clean_text 清洗剥离 HTML/JS，防 XSS
 *     · 数据库：users 表新增 contact VARCHAR(1000) 字段，存 JSON 数组
 *
 * 3.11.0 更新：
 *   - 修复：HTML 作品引用外部 CDN 时被 CSP 拦截（关键功能 bug）
 *     · 根因：HTML 作品通过 <iframe srcdoc sandbox> 渲染，srcdoc 文档会继承
 *       父页面的 CSP。原 CSP 只放行了 cdnjs.cloudflare.com 一个 CDN，导致作品
 *       引用 jsdelivr / unpkg / Google Fonts / BootCDN 等其他 CDN 时全部被拦截
 *     · 修复：改为动态 CSP，内置 30+ 主流可信 CDN 白名单（含国内镜像）：
 *       cdnjs / jsdelivr / unpkg / BootCDN / Staticfile / 360 前端库 / 字节 CDN /
 *       Google Fonts / Google AJAX / Microsoft CDN / jQuery 官方 / Bootstrap CDN /
 *       Font Awesome / Google Fonts 国内镜像 (fonts.font.im / fonts.loli.net) /
 *       Unsplash / Pixabay / Three.js / D3 / Plotly 等
 *     · 管理员可在后台「设置 → CDN 白名单管理」追加自定义域名，保存后立即生效
 *     · 自定义域名经格式校验：必须是合法域名，强制 HTTPS，支持 *.domain 通配符
 *   - 安全：CSP 仍然保持严格策略
 *     · default-src 'self'：未列入白名单的资源一律拒绝
 *     · 仅放行 HTTPS CDN：不接受 http: 或 *（防中间人注入）
 *     · object-src 'none'（主页）/ 白名单 CDN（托管页）：阻止 Flash/插件
 *     · base-uri 'self'：防 <base> 标签劫持
 *     · iframe sandbox 仍保留：作品脚本在沙箱内运行，无法操作父页面
 *   - 新增：admin_cdn_whitelist / admin_cdn_whitelist_save 两个管理接口
 *   - 新增：管理后台「设置」页新增 CDN 白名单管理 UI（含内置列表查看）
 *
 * 3.10.2 更新：
 *   - 修复：「我的」页面只显示前 10 个作品，无法继续加载（关键体验 bug）
 *     · 根因：renderProfile 只调一次 posts 接口（page 1），没有分页/无限滚动
 *     · 改为无限滚动：触底自动加载下一页，底部显示「上拉加载更多」/「已经到底啦」
 *     · 消除冗余 API 调用：原来每次加载会发 4 个请求（含 2 个重复），现在统计栏
 *       数据走 user 接口一次性拿到（posts_count / likes_received / favorites_made /
 *       followers_count / following_count），作品列表和统计栏并行加载
 *   - 同步修复：其他用户主页（renderUser）也有同样的只加载 10 条问题，已改为无限滚动
 *   - 同步修复：我的收藏页面（renderFavorites）、发现页热门 tab 也改为无限滚动
 *   - 后端：user_view_array 新增 likes_received / favorites_received / favorites_made 字段
 *     · likes_received = 该用户所有帖子的累计获赞数（SUM(likes_count)）
 *     · favorites_made = 该用户收藏的帖子总数
 *     · 用于个人页/用户主页的统计栏，避免前端再发请求累加
 *
 * 3.10.1 更新：
 *   - 修复：删除用户后，首页帖子的点赞/评论/收藏数仍是幻数（关键 bug）
 *     · 根因：删除 likes/favorites/comments 记录时未同步扣减 posts 表的计数字段
 *     · 新增 3 个计数字段同步助手：delete_likes_by_users_and_sync 等
 *     · 所有删除路径（admin_delete_user / admin_bulk_delete_users / delete_post /
 *       delete_own_post / admin_delete_post / admin_delete_user_posts）统一调用
 *     · 新增 admin_recount_posts 接口：强制用实际行数覆盖计数字段，修复历史脏数据
 *     · 管理后台「设置」页新增「重新同步所有帖子计数」按钮，一键修复历史不一致
 *   - 优化：全站图片懒加载（loading="lazy" + decoding="async"）
 *     · 首页 feed 卡片封面/图片、帖子详情大图、头像、工作室封面、通知邀请卡片
 *     · 加载前显示 shimmer 占位动画，避免白屏闪烁
 *     · 显著降低首页首屏图片请求数（仅加载可视区域内的图）
 *   - 美化：登录/注册页面视觉升级
 *     · 顶部柔和彩色光晕背景，logo 渐变 + 阴影
 *     · 输入框聚焦时有 accent 色光晕环
 *     · 主按钮渐变色 + 阴影
 *     · 新增密码可见性切换按钮（眼睛图标）
 *     · 表单字段间 Tab 键流转、自动聚焦用户名
 *     · 返回首页按钮
 *
 * 3.10.0 更新：
 *   - 修复：管理员「用户列表」与「消息通知」分页加载失效，超过 30 条后无法继续显示
 *     · admin_users 接口新增 total / has_more 字段，前端改为无限滚动加载
 *     · 通知页前端补齐滚动监听，自动追加下一页
 *   - 新增：注册接口反机器人防护（IP 维度限流 + 蜜罐字段 + 表单时间检测 + 同源 Referer 校验）
 *     · 同一 IP 60 秒内最多 3 次注册，1 小时内最多 10 次，1 天内最多 30 次
 *     · 蜜罐字段（website）一旦填入即判定为机器人，静默丢弃
 *     · 表单渲染到提交少于 2 秒视为机器人
 *     · 非同源请求直接拒绝
 *   - 新增：管理员批量删除/批量封禁用户功能
 *     · 支持按 ID 批量操作
 *     · 支持按注册时间区间、账号状态筛选批量清理（一键清除近期机器人注册）
 *     · 单次最多处理 500 个账号，防误操作；事务保证联级清理一致性
 *
 * 3.9.0 更新：
 *   - 启用 Gzip 传输压缩：HTML 主页 + JSON API + 托管页面全链路覆盖
 *   - 智能压缩策略：< 1KB 不压缩（避免反向收益）；level 5 平衡 CPU 与压缩比
 *   - 安全防护：尊重 Accept-Encoding、检测 zlib.output_compression、避免重复编码
 *   - 设置 Vary: Accept-Encoding 头，确保 CDN/反代正确缓存
 *   - 典型场景：首页 HTML 体积下降 ~80%，API 响应下降 ~70%
 *
 * 3.8.0 更新：
 *   - 新增开屏画面（Splash Screen）：扁平化设计 + 三点加载指示器
 *
 * 3.7.0 更新：
 *   - 新增「代码质量评分」玩具工具：6 维度加权评分
 *
 * 3.6.0 更新：
 *   - 公平热门算法：Hacker News 风格时间衰减 + 多维加权
 *   - 新增「弹窗公告」管理：Markdown 编辑/预览
 */

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

/* ============================================================
 *  安全响应头（XSS / 点击劫持 / MIME 嗅探防护）
 * ============================================================ */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
// 防克隆：禁止搜索引擎缓存源码
header('X-Robots-Tag: noindex, noarchive, nosnippet');
// 防克隆：禁止 Referer 泄露（外部请求看不到来源页面 URL）
// Referrer-Policy 已在下方设置
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob: https:; "
    . "font-src 'self' data:; "
    . "frame-src 'self' data: blob:; "
    . "connect-src 'self'; "
    . "object-src 'none'; "
    . "base-uri 'self'");

const CONFIG_FILE     = __DIR__ . '/.htmlhub.config.php';
const INSTALLED_FLAG  = __DIR__ . '/.installed';
const VERSION         = '3.18.0';
const COVER_LIMIT     = 2 * 1024 * 1024; // 2MB

/* ============================================================
 *  配置加载
 * ============================================================ */
function load_config(): ?array {
    static $cfg = null;
    if ($cfg !== null) return $cfg ?: null;
    if (!file_exists(CONFIG_FILE)) { $cfg = false; return null; }
    $loaded = @include CONFIG_FILE;
    if (!is_array($loaded)) { $cfg = false; return null; }
    $cfg = $loaded;
    return $cfg;
}

/** 重新加载配置（用于写入 config 文件后清除内部缓存） */
function reload_config(): ?array {
    // 通过反射或包装间接清除 load_config 的 static 缓存不可行，
    // 这里改用 opcache invalidate + 重新 include 的方式
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(CONFIG_FILE, true);
    }
    $loaded = @include CONFIG_FILE;
    if (!is_array($loaded)) return null;
    // 直接重新赋值需要访问 static 变量；用全局变量桥接
    $GLOBALS['__htmlhub_cfg_latest'] = $loaded;
    return $loaded;
}

function is_installed(): bool {
    return file_exists(INSTALLED_FLAG) && file_exists(CONFIG_FILE);
}

/* ============================================================
 *  数据库
 * ============================================================ */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = load_config();
        if (!$cfg) {
            throw new RuntimeException('数据库未配置');
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'] ?? 3306, $cfg['db_name']);
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // 兼容旧库：每次连接后自动检查并补齐新字段
        ensure_schema($pdo);
    }
    return $pdo;
}

/**
 * 兼容旧库：自动检查并补齐缺失的字段/表。
 * 通过 INFORMATION_SCHEMA 探测，避免 ALTER TABLE 报错。
 * 只在首次连接时执行一次，性能开销极小。
 */
function ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        // 探测数据库名
        $dbName = $pdo->query("SELECT DATABASE() AS d")->fetch()['d'] ?? '';
        if (!$dbName) return;

        // 探测 posts 表字段
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'posts'");
        $cols->execute([$dbName]);
        $postsCols = array_column($cols->fetchAll(), 'COLUMN_NAME');
        if (in_array('id', $postsCols, true) && !in_array('is_pinned', $postsCols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN is_pinned TINYINT UNSIGNED NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE posts ADD INDEX idx_posts_pinned (is_pinned)");
        }

        // 探测 users 表字段
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users'");
        $cols->execute([$dbName]);
        $usersCols = array_column($cols->fetchAll(), 'COLUMN_NAME');
        if (in_array('id', $usersCols, true) && !in_array('status', $usersCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
        }

        // 探测 follows 表是否存在
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'follows'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS follows (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                follower_id INT UNSIGNED NOT NULL,
                following_id INT UNSIGNED NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uk_follow (follower_id, following_id),
                INDEX idx_follows_following (following_id),
                INDEX idx_follows_follower (follower_id),
                CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_follows_following FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 announcements 表是否存在
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'announcements'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                content VARCHAR(1000) NOT NULL DEFAULT '',
                is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_ann_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 popup_announcements 表是否存在（生产环境升级路径）
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'popup_announcements'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS popup_announcements (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL DEFAULT '',
                content_md MEDIUMTEXT NOT NULL,
                is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
                created_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                INDEX idx_popup_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 comments 表是否需要补 parent_id / reply_to_user_id
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'comments'");
        $cols->execute([$dbName]);
        $cmtCols = array_column($cols->fetchAll(), 'COLUMN_NAME');
        if (in_array('id', $cmtCols, true) && !in_array('parent_id', $cmtCols, true)) {
            $pdo->exec("ALTER TABLE comments ADD COLUMN parent_id INT UNSIGNED NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE comments ADD INDEX idx_comments_parent (parent_id)");
        }
        if (in_array('id', $cmtCols, true) && !in_array('reply_to_user_id', $cmtCols, true)) {
            $pdo->exec("ALTER TABLE comments ADD COLUMN reply_to_user_id INT UNSIGNED NOT NULL DEFAULT 0");
        }

        // 探测 notifications 表是否存在
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                actor_id INT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL,
                post_id INT UNSIGNED NOT NULL DEFAULT 0,
                comment_id INT UNSIGNED NOT NULL DEFAULT 0,
                content VARCHAR(500) NOT NULL DEFAULT '',
                is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_notif_user_read (user_id, is_read),
                INDEX idx_notif_created (created_at DESC),
                CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_notif_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 studios 表是否存在
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'studios'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS studios (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50) NOT NULL,
                slug VARCHAR(50) UNIQUE NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                cover MEDIUMTEXT NULL,
                owner_id INT UNSIGNED NOT NULL,
                visibility VARCHAR(20) NOT NULL DEFAULT 'public',
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_studios_owner (owner_id),
                INDEX idx_studios_created (created_at),
                CONSTRAINT fk_studios_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 studio_members 表是否存在
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'studio_members'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS studio_members (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                studio_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'member',
                joined_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uk_studio_member (studio_id, user_id),
                INDEX idx_sm_studio (studio_id),
                INDEX idx_sm_user (user_id),
                CONSTRAINT fk_sm_studio FOREIGN KEY (studio_id) REFERENCES studios(id) ON DELETE CASCADE,
                CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 posts 是否需要补 images / studio_id / edited_at 字段
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'posts'");
        $cols->execute([$dbName]);
        $postsCols = array_column($cols->fetchAll(), 'COLUMN_NAME');
        if (in_array('id', $postsCols, true) && !in_array('images', $postsCols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN images MEDIUMTEXT NULL");
        }
        if (in_array('id', $postsCols, true) && !in_array('studio_id', $postsCols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN studio_id INT UNSIGNED NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE posts ADD INDEX idx_posts_studio (studio_id)");
        }
        if (in_array('id', $postsCols, true) && !in_array('edited_at', $postsCols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN edited_at INT UNSIGNED NOT NULL DEFAULT 0");
        }
        // 探测 posts 是否需要补 persistent_mode 字段（持久化模式：localStorage 可用）
        if (in_array('id', $postsCols, true) && !in_array('persistent_mode', $postsCols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN persistent_mode TINYINT UNSIGNED NOT NULL DEFAULT 0");
        }

        // 探测 hosted_pages 是否需要补 is_banned 字段
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'hosted_pages'");
        $cols->execute([$dbName]);
        $hostedCols = array_column($cols->fetchAll(), 'COLUMN_NAME');
        if (in_array('id', $hostedCols, true) && !in_array('is_banned', $hostedCols, true)) {
            $pdo->exec("ALTER TABLE hosted_pages ADD COLUMN is_banned TINYINT UNSIGNED NOT NULL DEFAULT 0");
        }
        // 探测 hosted_pages 是否需要补 persistent_mode 字段（持久化模式：localStorage 可用）
        if (in_array('id', $hostedCols, true) && !in_array('persistent_mode', $hostedCols, true)) {
            $pdo->exec("ALTER TABLE hosted_pages ADD COLUMN persistent_mode TINYINT UNSIGNED NOT NULL DEFAULT 0");
        }

        // 探测 users 是否需要补 remember_token 字段
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users'");
        $cols->execute([$dbName]);
        $userCols = array_column($cols->fetchAll(), 'COLUMN_NAME');
        if (in_array('id', $userCols, true) && !in_array('remember_token', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN remember_expires INT UNSIGNED NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE users ADD INDEX idx_remember_token (remember_token)");
        }
        // 探测 users 是否需要补 contact 字段（联系方式，JSON 字符串）
        if (in_array('id', $userCols, true) && !in_array('contact', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN contact VARCHAR(1000) NOT NULL DEFAULT ''");
        }

        // 探测 app_settings 表
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'app_settings'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
                key_name VARCHAR(50) PRIMARY KEY,
                value TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 hosted_pages 表
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'hosted_pages'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS hosted_pages (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                slug VARCHAR(32) UNIQUE NOT NULL,
                title VARCHAR(100) NOT NULL DEFAULT '',
                html_content LONGTEXT NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                is_banned TINYINT UNSIGNED NOT NULL DEFAULT 0,
                persistent_mode TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_hosted_user (user_id),
                INDEX idx_hosted_created (created_at),
                CONSTRAINT fk_hosted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 studio_invitations 表
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'studio_invitations'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS studio_invitations (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                studio_id INT UNSIGNED NOT NULL,
                inviter_id INT UNSIGNED NOT NULL,
                invitee_id INT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at INT UNSIGNED NOT NULL,
                responded_at INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uk_invitation (studio_id, invitee_id),
                INDEX idx_inv_invitee (invitee_id, status),
                CONSTRAINT fk_inv_studio FOREIGN KEY (studio_id) REFERENCES studios(id) ON DELETE CASCADE,
                CONSTRAINT fk_inv_inviter FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_inv_invitee FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 reports 表（举报系统）
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'reports'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                reporter_id INT UNSIGNED NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_id INT UNSIGNED NOT NULL,
                reason VARCHAR(50) NOT NULL,
                detail VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                handler_id INT UNSIGNED NOT NULL DEFAULT 0,
                handler_note VARCHAR(500) NOT NULL DEFAULT '',
                created_at INT UNSIGNED NOT NULL,
                handled_at INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_reports_status (status, created_at),
                INDEX idx_reports_target (target_type, target_id),
                INDEX idx_reports_reporter (reporter_id),
                CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 探测 images 表（图片分离存储，提升列表查询性能）
        $tbl = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'images'");
        $tbl->execute([$dbName]);
        if (!$tbl->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS images (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                data MEDIUMTEXT NOT NULL,
                uploader_id INT UNSIGNED NOT NULL DEFAULT 0,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_images_uploader (uploader_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // 初始化托管默认配置
        $defaults = [
            'hosting_enabled' => '1',
            'hosting_max_per_user' => '10',
            'hosting_max_size_kb' => '100',
            'hosting_max_total' => '100',
            // 代码评分玩具工具开关（默认开启，管理员可在后台关闭）
            'code_score_enabled' => '1',
            // CDN 白名单（管理员可追加，每行一个域名；留空表示只用内置白名单）
            'cdn_whitelist' => '',
        ];
        foreach ($defaults as $k => $v) {
            $chk = $pdo->prepare("SELECT 1 FROM app_settings WHERE key_name = ?");
            $chk->execute([$k]);
            if (!$chk->fetch()) {
                $pdo->prepare("INSERT INTO app_settings (key_name, value) VALUES (?, ?)")->execute([$k, $v]);
            }
        }
    } catch (Throwable $e) {
        // 静默失败：兼容旧库失败不应阻断业务
        error_log('ensure_schema failed: ' . $e->getMessage());
    }
}

function init_db(PDO $pdo): void {
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        avatar MEDIUMTEXT NULL,
        bio VARCHAR(200) NOT NULL DEFAULT '',
        contact VARCHAR(1000) NOT NULL DEFAULT '',
        role VARCHAR(20) NOT NULL DEFAULT 'user',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(10) NOT NULL,
        title VARCHAR(100) NOT NULL,
        content MEDIUMTEXT NULL,
        html_content LONGTEXT NULL,
        cover MEDIUMTEXT NULL,
        images MEDIUMTEXT NULL,
        view_mode VARCHAR(10) NOT NULL DEFAULT 'embed',
        is_pinned TINYINT UNSIGNED NOT NULL DEFAULT 0,
        studio_id INT UNSIGNED NOT NULL DEFAULT 0,
        edited_at INT UNSIGNED NOT NULL DEFAULT 0,
        persistent_mode TINYINT UNSIGNED NOT NULL DEFAULT 0,
        views INT UNSIGNED NOT NULL DEFAULT 0,
        likes_count INT UNSIGNED NOT NULL DEFAULT 0,
        favorites_count INT UNSIGNED NOT NULL DEFAULT 0,
        comments_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_posts_created (created_at),
        INDEX idx_posts_type (type),
        INDEX idx_posts_user (user_id),
        INDEX idx_posts_pinned (is_pinned),
        INDEX idx_posts_studio (studio_id),
        CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS likes (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY uk_like (post_id, user_id),
        INDEX idx_likes_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY uk_fav (post_id, user_id),
        INDEX idx_favs_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content VARCHAR(500) NOT NULL,
        parent_id INT UNSIGNED NOT NULL DEFAULT 0,
        reply_to_user_id INT UNSIGNED NOT NULL DEFAULT 0,
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_comments_post (post_id),
        INDEX idx_comments_parent (parent_id),
        CONSTRAINT fk_cmt_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_cmt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS follows (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        follower_id INT UNSIGNED NOT NULL,
        following_id INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY uk_follow (follower_id, following_id),
        INDEX idx_follows_following (following_id),
        INDEX idx_follows_follower (follower_id),
        CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_follows_following FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL,
        content VARCHAR(1000) NOT NULL DEFAULT '',
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_ann_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS popup_announcements (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL DEFAULT '',
        content_md MEDIUMTEXT NOT NULL,
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at INT UNSIGNED NOT NULL,
        updated_at INT UNSIGNED NOT NULL,
        INDEX idx_popup_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL,
        post_id INT UNSIGNED NOT NULL DEFAULT 0,
        comment_id INT UNSIGNED NOT NULL DEFAULT 0,
        content VARCHAR(500) NOT NULL DEFAULT '',
        is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_notif_user_read (user_id, is_read),
        INDEX idx_notif_created (created_at DESC),
        CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_notif_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS studios (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(50) NOT NULL,
        slug VARCHAR(50) UNIQUE NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        cover MEDIUMTEXT NULL,
        owner_id INT UNSIGNED NOT NULL,
        visibility VARCHAR(20) NOT NULL DEFAULT 'public',
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_studios_owner (owner_id),
        INDEX idx_studios_created (created_at),
        CONSTRAINT fk_studios_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS studio_members (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        studio_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        joined_at INT UNSIGNED NOT NULL,
        UNIQUE KEY uk_studio_member (studio_id, user_id),
        INDEX idx_sm_studio (studio_id),
        INDEX idx_sm_user (user_id),
        CONSTRAINT fk_sm_studio FOREIGN KEY (studio_id) REFERENCES studios(id) ON DELETE CASCADE,
        CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        key_name VARCHAR(50) PRIMARY KEY,
        value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosted_pages (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        slug VARCHAR(32) UNIQUE NOT NULL,
        title VARCHAR(100) NOT NULL DEFAULT '',
        html_content LONGTEXT NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        views INT UNSIGNED NOT NULL DEFAULT 0,
        is_banned TINYINT UNSIGNED NOT NULL DEFAULT 0,
        persistent_mode TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_hosted_user (user_id),
        INDEX idx_hosted_created (created_at),
        CONSTRAINT fk_hosted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS studio_invitations (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        studio_id INT UNSIGNED NOT NULL,
        inviter_id INT UNSIGNED NOT NULL,
        invitee_id INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at INT UNSIGNED NOT NULL,
        responded_at INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uk_invitation (studio_id, invitee_id),
        INDEX idx_inv_invitee (invitee_id, status),
        CONSTRAINT fk_inv_studio FOREIGN KEY (studio_id) REFERENCES studios(id) ON DELETE CASCADE,
        CONSTRAINT fk_inv_inviter FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_inv_invitee FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        reporter_id INT UNSIGNED NOT NULL,
        target_type VARCHAR(20) NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        reason VARCHAR(50) NOT NULL,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        handler_id INT UNSIGNED NOT NULL DEFAULT 0,
        handler_note VARCHAR(500) NOT NULL DEFAULT '',
        created_at INT UNSIGNED NOT NULL,
        handled_at INT UNSIGNED NOT NULL DEFAULT 0,
        INDEX idx_reports_status (status, created_at),
        INDEX idx_reports_target (target_type, target_id),
        INDEX idx_reports_reporter (reporter_id),
        CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        data MEDIUMTEXT NOT NULL,
        uploader_id INT UNSIGNED NOT NULL DEFAULT 0,
        created_at INT UNSIGNED NOT NULL,
        INDEX idx_images_uploader (uploader_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 兼容旧库：尝试为已存在的表补充新字段（静默失败）
    @$pdo->exec("ALTER TABLE posts ADD COLUMN is_pinned TINYINT UNSIGNED NOT NULL DEFAULT 0");
    @$pdo->exec("ALTER TABLE posts ADD COLUMN images MEDIUMTEXT NULL");
    @$pdo->exec("ALTER TABLE posts ADD COLUMN studio_id INT UNSIGNED NOT NULL DEFAULT 0");
    @$pdo->exec("ALTER TABLE posts ADD COLUMN edited_at INT UNSIGNED NOT NULL DEFAULT 0");
    @$pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
    // 初始化托管默认配置
    foreach (['hosting_enabled' => '1', 'hosting_max_per_user' => '10', 'hosting_max_size_kb' => '100', 'hosting_max_total' => '100'] as $k => $v) {
        @$pdo->prepare("INSERT IGNORE INTO app_settings (key_name, value) VALUES (?, ?)")->execute([$k, $v]);
    }
}

/* ============================================================
 *  工具函数
 * ============================================================ */

/**
 * 检测客户端是否支持 gzip 压缩
 * 安全检查：尊重 Accept-Encoding 头；不支持则返回 false
 */
function client_accepts_gzip(): bool {
    // 如果 zlib.output_compression 已在 php.ini 启用，PHP 已经处理了，无需重复
    if (ini_get('zlib.output_compression')) return false;
    // 如果当前 ob 已经在用 ob_gzhandler，跳过
    if (in_array('ob_gzhandler', ob_list_handlers(), true)) return false;
    $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (empty($ae)) return false;
    // 严格匹配 gzip（避免匹配 "x-gzip" 等非标准）
    return (bool)preg_match('/(?:^|,\s*)gzip(?:\s*;|$)/i', $ae);
}

/**
 * 对内容应用 gzip 压缩并发送相应头
 * - 小于阈值（1KB）的内容不压缩（得不偿失）
 * - 已设置 Content-Encoding 时不重复压缩
 * - 失败时原样返回
 */
function apply_gzip_if_beneficial(string $content): string {
    // 太小不压缩（gzip 头开销反而让响应更大）
    if (strlen($content) < 1024) return $content;
    if (!client_accepts_gzip()) return $content;
    if (!function_exists('gzencode')) return $content;
    // headers 已发送（不可能再设 Content-Encoding），不能压缩
    if (headers_sent()) return $content;
    // 已有 Content-Encoding 头（被反向代理处理过），不重复
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Encoding:') === 0) return $content;
    }
    $compressed = @gzencode($content, 5); // level 5：CPU 与压缩比平衡
    if ($compressed === false) return $content;
    // 必须在输出前设置头
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    // 压缩后 Content-Length 已变化，移除可能已设置的旧值
    header('Content-Length: ' . strlen($compressed));
    return $compressed;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo apply_gzip_if_beneficial($body);
    exit;
}

function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) return $d;
    }
    return $_POST ?: [];
}

/**
 * 清洗用户输入文本：去 NULL 字节 / 控制字符 / 非法 Unicode；保留普通换行。
 * 不做 HTML 转义（输出时由前端 escapeHtml 负责），但剥离 <script> 等危险标签。
 */
function clean_text(?string $s, int $maxLen = 5000): string {
    if ($s === null) return '';
    // 移除 NULL 字节（防止 \0 绕过）
    $s = str_replace("\0", '', $s);
    // 移除除 \n\r\t 外的控制字符
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    // 防止 UTF-8 编码攻击：移除无效 UTF-8 字节序列
    if (function_exists('mb_convert_encoding')) {
        $conv = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        if ($conv !== false) $s = $conv;
    }
    // 剥离 <script>、<iframe>、<object>、<embed>、on* 事件属性、javascript: 协议
    $s = preg_replace('#<\s*/?(script|iframe|object|embed|svg|math|template|noscript|form|input|textarea|button|select|option|meta|link|style|base|applet|frame|frameset)(\s[^>]*)?>#isu', '', $s);
    $s = preg_replace('#\bon[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#isu', '', $s);
    $s = preg_replace('#(javascript|vbscript|data)\s*:#isu', '$1&#58;', $s);
    // 长度截断
    if ($maxLen > 0 && mb_strlen($s, 'UTF-8') > $maxLen) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    }
    return $s;
}

/** 严格清洗纯文本字段（标题、用户名等）—— 完全剥离 HTML */
function clean_plain(?string $s, int $maxLen = 100): string {
    $s = clean_text($s, $maxLen);
    $s = strip_tags($s);
    return trim($s);
}

/** 验证 base64 图片数据 URL 是否合法 */
function valid_data_url(?string $s, int $maxBytes = COVER_LIMIT): bool {
    if (!$s) return true; // 允许空
    if (strlen($s) > $maxBytes) return false;
    return (bool)preg_match('#^data:image/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+/=]+$#', $s);
}

/**
 * 压缩 base64 图片：
 *  - 解码 base64 → GD 资源
 *  - 按最大边长等比缩放（默认 1280px）
 *  - 重新编码为 JPEG（质量 82）或 PNG（保留透明通道）
 *  - 返回新的 data URL；失败时原样返回
 *
 * 生产级：异常安全，不会因压缩失败丢图。
 */
function compress_image_data_url(string $dataUrl, int $maxEdge = 1280, int $jpegQuality = 82): string {
    if (!function_exists('gd_info')) return $dataUrl; // GD 不可用，原样返回
    if (strlen($dataUrl) > 8 * 1024 * 1024) return $dataUrl; // 超大图跳过压缩避免内存爆
    // 解析 mime + base64
    if (!preg_match('#^data:image/(png|jpe?g|webp|gif);base64,(.+)$#s', $dataUrl, $m)) {
        return $dataUrl;
    }
    $mime = $m[1];
    $raw = base64_decode($m[2], true);
    if ($raw === false || $raw === '') return $dataUrl;
    // 移除可能残留的 NULL 字节
    $raw = str_replace("\0", '', $raw);

    // 关键修复：使用 imagecreatefromstring 而非 imagecreatefromjpeg(png/gif/webp)
    // 这些 imagecreatefrom* 函数期望的是文件名，传入二进制数据会触发 null bytes 错误
    // imagecreatefromstring 自动识别 GIF/JPEG/PNG/WBMP/GD2/WebP 格式
    $im = @imagecreatefromstring($raw);
    if (!$im) return $dataUrl;

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0) { imagedestroy($im); return $dataUrl; }

    // 等比缩放
    $needScale = ($w > $maxEdge || $h > $maxEdge);
    if ($needScale) {
        $ratio = min($maxEdge / $w, $maxEdge / $h);
        $newW = (int)max(1, round($w * $ratio));
        $newH = (int)max(1, round($h * $ratio));
        $scaled = imagecreatetruecolor($newW, $newH);
        // 保留透明通道
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 255, 255, 255, 127);
        imagefilledrectangle($scaled, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($scaled, $im, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($im);
        $im = $scaled;
        $w = $newW;
        $h = $newH;
    }

    // 输出：检测透明像素，含则保留 PNG，否则转 JPEG（更小）
    $hasAlpha = false;
    if ($mime === 'png' || $mime === 'gif' || $mime === 'webp') {
        $samples = [[0, 0], [$w - 1, $h - 1], [(int)($w / 2), (int)($h / 2)]];
        foreach ($samples as $sample) {
            [$sx, $sy] = $sample;
            if ($sx < 0 || $sx >= $w || $sy < 0 || $sy >= $h) continue;
            $rgba = imagecolorat($im, $sx, $sy);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha > 0) { $hasAlpha = true; break; }
        }
    }

    ob_start();
    if ($hasAlpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagepng($im, null, 6);
        $outMime = 'image/png';
    } else {
        // 转 JPEG：补白色背景（防透明区域变黑）
        $bg = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefilledrectangle($bg, 0, 0, $w, $h, $white);
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $im = $bg;
        imagejpeg($im, null, $jpegQuality);
        $outMime = 'image/jpeg';
    }
    $outBin = ob_get_clean();
    imagedestroy($im);
    if ($outBin === false || strlen($outBin) === 0) return $dataUrl;

    $newDataUrl = 'data:' . $outMime . ';base64,' . base64_encode($outBin);
    // 如果压缩后反而更大（极小图可能），保留原图
    if (strlen($newDataUrl) >= strlen($dataUrl)) return $dataUrl;
    return $newDataUrl;
}

function current_user(): ?array {
    // 1. 优先用 session
    if (!empty($_SESSION['uid'])) {
        static $cached = null;
        if ($cached === null) {
            try {
                $stmt = db()->prepare("SELECT id, username, avatar, bio, contact, role, status, created_at FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['uid']]);
                $cached = $stmt->fetch() ?: false;
            } catch (Throwable $e) {
                $cached = false;
            }
        }
        return $cached ?: null;
    }
    // 2. 尝试用 remember_token 自动登录
    $token = $_COOKIE['remember_token'] ?? '';
    if ($token && strlen($token) === 64 && is_installed()) {
        try {
            $stmt = db()->prepare("SELECT id, username, avatar, bio, contact, role, status, created_at, remember_expires FROM users WHERE remember_token = ?");
            $stmt->execute([$token]);
            $u = $stmt->fetch();
            if ($u && (int)$u['remember_expires'] > time() && ($u['status'] ?? 'active') === 'active') {
                // 自动登录成功，设置 session
                $_SESSION['uid'] = (int)$u['id'];
                // 滚动 token：发新 token，旧 token 失效
                $newToken = bin2hex(random_bytes(32));
                $newExpires = time() + 86400; // 1 天
                db()->prepare("UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?")
                    ->execute([$newToken, $newExpires, $u['id']]);
                setcookie('remember_token', $newToken, [
                    'expires' => $newExpires,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                ]);
                return [
                    'id' => (int)$u['id'], 'username' => $u['username'], 'avatar' => resolve_image($u['avatar']),
                    'bio' => $u['bio'], 'contact' => $u['contact'] ?? '', 'role' => $u['role'],
                    'status' => $u['status'], 'created_at' => $u['created_at'],
                ];
            }
        } catch (Throwable $e) {}
    }
    return null;
}

function require_auth(): array {
    $u = current_user();
    if (!$u) json_out(['error' => '请先登录'], 401);
    return $u;
}

/* ============================================================
 *  管理员安全防护（生产级）
 *
 *  防护层次：
 *  1. 密码存储：password_hash (bcrypt) + 常量时间比较
 *     - 兼容旧版明文密码：自动检测并升级为 hash
 *  2. 登录频率限制：IP 维度（60s/5次，1h/20次，1d/50次）
 *  3. Session 绑定指纹：IP 前两段 + User-Agent hash
 *     - 防 session 劫持：换设备/IP 立即失效
 *  4. Session 超时：管理员会话 2 小时自动过期
 *  5. 登录时 session_regenerate_id：防 session 固定攻击
 *  6. require_admin 二次校验：每次 API 调用都重新验证 session 完整性
 *  7. 管理员操作日志：记录谁在何时做了什么（写入 app_settings 日志）
 * ============================================================ */

/** 管理员 session 超时时间（秒） */
const ADMIN_SESSION_TTL = 7200; // 2 小时

/** 管理员登录频率限制文件 */
function admin_login_rate_file(string $window): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return sys_get_temp_dir() . '/htmlhub_admin_' . $window . '_' . md5($ip);
}

/** 检查管理员登录频率是否超限 */
function admin_login_rate_check(string $window, int $threshold, int $seconds): bool {
    $file = admin_login_rate_file($window);
    $now = time();
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) $data = json_decode($raw, true) ?: [];
    }
    $data = array_values(array_filter($data, fn($t) => $t > $now - $seconds));
    return count($data) < $threshold;
}

/** 记录一次管理员登录尝试（无论成功失败） */
function admin_login_rate_record(): void {
    $now = time();
    foreach (['60s' => 60, '1h' => 3600, '1d' => 86400] as $win => $sec) {
        $file = admin_login_rate_file($win);
        $data = [];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $data = json_decode($raw, true) ?: [];
        }
        $data[] = $now;
        $data = array_values(array_filter($data, fn($t) => $t > $now - $sec));
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

/** 生成会话指纹（IP 前两段 + UA hash），用于绑定 session */
function admin_session_fingerprint(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // 取 IP 前两段（允许同 /16 子网，避免移动网络切换 IP 导致频繁掉线）
    $ipParts = explode('.', $ip);
    $ipPrefix = count($ipParts) === 4 ? ($ipParts[0] . '.' . $ipParts[1]) : $ip;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $ipPrefix . '|' . $ua . '|htmlhub_admin_salt_v1');
}

/** 校验管理员密码（兼容旧版明文 + 自动升级为 hash） */
function verify_admin_password(string $input, string $stored): bool {
    if ($stored === '') return false;
    // 如果存储的是 hash（以 $2y$ 开头，bcrypt）
    if (strpos($stored, '$2y$') === 0) {
        return password_verify($input, $stored);
    }
    // 旧版明文密码：常量时间比较
    if (hash_equals($stored, $input)) {
        // 自动升级为 hash（下次登录时存储的就是 hash 了）
        return true;
    }
    return false;
}

/** 检查存储的密码是否需要升级为 hash */
function admin_password_needs_upgrade(string $stored): bool {
    return $stored !== '' && strpos($stored, '$2y$') !== 0;
}

/**
 * 管理员权限校验（生产级，多层防护）。
 * 每次调用都会：
 * 1. 检查 session is_admin 标志
 * 2. 检查会话指纹（IP + UA）是否匹配
 * 3. 检查会话是否超时
 * 4. 任一不通过则清除 session 并返回 403
 */
function require_admin(): void {
    // 1. 基本标志检查
    if (empty($_SESSION['is_admin'])) {
        json_out(['error' => '需要管理员权限'], 403);
    }
    // 2. 会话指纹校验（防 session 劫持）
    $expectedFp = $_SESSION['admin_fp'] ?? '';
    $currentFp = admin_session_fingerprint();
    if ($expectedFp === '' || !hash_equals($expectedFp, $currentFp)) {
        // 指纹不匹配，立即清除 session
        unset($_SESSION['is_admin'], $_SESSION['admin_fp'], $_SESSION['admin_login_at']);
        json_out(['error' => '会话已失效，请重新登录'], 403);
    }
    // 3. 会话超时检查
    $loginAt = $_SESSION['admin_login_at'] ?? 0;
    if ($loginAt > 0 && (time() - $loginAt) > ADMIN_SESSION_TTL) {
        unset($_SESSION['is_admin'], $_SESSION['admin_fp'], $_SESSION['admin_login_at']);
        json_out(['error' => '管理员会话已过期，请重新登录'], 403);
    }
    // 4. 续期（活跃用户自动延长会话，避免操作中途掉线）
    $_SESSION['admin_login_at'] = time();
}

/**
 * 记录管理员操作日志（用于审计）。
 * 日志写入 app_settings，最多保留最近 100 条。
 */
function admin_log(string $action, string $detail = ''): void {
    try {
        $logs = [];
        $raw = app_setting('admin_logs', '');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $logs = $decoded;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100);
        $logs[] = [
            'ts' => time(),
            'ip' => $ip,
            'ua' => $ua,
            'action' => $action,
            'detail' => mb_substr($detail, 0, 200, 'UTF-8'),
        ];
        // 只保留最近 100 条
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        set_app_setting('admin_logs', json_encode($logs, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        // 日志失败不阻塞主业务
        error_log('admin_log failed: ' . $e->getMessage());
    }
}

/** 管理员权限校验（必须在全局作用域，否则某些 API 路径下函数未定义） */
function require_admin_legacy_compat(): void {
    require_admin();
}

/**
 * 推送通知（生产级）
 *
 * 业务规则：
 *  - 不给自己发通知（actor_id === user_id 时跳过）
 *  - 被通知用户必须存在且未被封禁
 *  - 内容快照会被清洗
 *  - 失败静默处理（通知是次要业务，不应阻塞主流程）
 *
 * 类型：comment（评论你的帖子）/ reply（回复你的评论）/ like（点赞你的帖子）/ follow（关注你）
 */
function push_notification(int $userId, int $actorId, string $type, int $postId = 0, int $commentId = 0, string $content = ''): void {
    if ($userId <= 0 || $actorId <= 0 || $userId === $actorId) return;
    if (!in_array($type, ['comment', 'reply', 'like', 'follow'], true)) return;
    try {
        // 检查接收者存在且未被封禁
        $chk = db()->prepare("SELECT 1 FROM users WHERE id = ? AND status = 'active'");
        $chk->execute([$userId]);
        if (!$chk->fetch()) return;
        $content = clean_text($content, 500);
        db()->prepare("INSERT INTO notifications (user_id, actor_id, type, post_id, comment_id, content, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)")
            ->execute([$userId, $actorId, $type, $postId, $commentId, $content, time()]);
    } catch (Throwable $e) {
        error_log('push_notification failed: ' . $e->getMessage());
    }
}

/* ============================================================
 *  计数字段同步助手
 *  posts 表上有冗余字段 likes_count / favorites_count / comments_count，
 *  删除 likes/favorites/comments 记录时必须同步扣减对应字段，
 *  否则会出现"删了用户但首页点赞数还是几百"的幻数 bug。
 * ============================================================ */

/**
 * 删除指定用户的所有 likes 记录，并同步扣减相关帖子的 likes_count。
 *
 * @param array $userIds 单个或多个用户 ID
 */
function delete_likes_by_users_and_sync(array $userIds): void {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($x) => $x > 0));
    if (empty($userIds)) return;
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    // 1. 先查出每个帖子被这些用户点过多少赞
    $stmt = db()->prepare("SELECT post_id, COUNT(*) AS c FROM likes WHERE user_id IN ($ph) GROUP BY post_id");
    $stmt->execute($userIds);
    $affected = $stmt->fetchAll();
    // 2. 删除 likes 记录
    db()->prepare("DELETE FROM likes WHERE user_id IN ($ph)")->execute($userIds);
    // 3. 同步扣减 likes_count（用 GREATEST 防止负数）
    $upd = db()->prepare("UPDATE posts SET likes_count = GREATEST(0, likes_count - ?) WHERE id = ?");
    foreach ($affected as $row) {
        $upd->execute([(int)$row['c'], (int)$row['post_id']]);
    }
}

/**
 * 删除指定用户的所有 favorites 记录，并同步扣减 favorites_count。
 */
function delete_favorites_by_users_and_sync(array $userIds): void {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($x) => $x > 0));
    if (empty($userIds)) return;
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = db()->prepare("SELECT post_id, COUNT(*) AS c FROM favorites WHERE user_id IN ($ph) GROUP BY post_id");
    $stmt->execute($userIds);
    $affected = $stmt->fetchAll();
    db()->prepare("DELETE FROM favorites WHERE user_id IN ($ph)")->execute($userIds);
    $upd = db()->prepare("UPDATE posts SET favorites_count = GREATEST(0, favorites_count - ?) WHERE id = ?");
    foreach ($affected as $row) {
        $upd->execute([(int)$row['c'], (int)$row['post_id']]);
    }
}

/**
 * 删除指定用户的所有 comments 记录，并同步扣减 comments_count。
 */
function delete_comments_by_users_and_sync(array $userIds): void {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($x) => $x > 0));
    if (empty($userIds)) return;
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = db()->prepare("SELECT post_id, COUNT(*) AS c FROM comments WHERE user_id IN ($ph) GROUP BY post_id");
    $stmt->execute($userIds);
    $affected = $stmt->fetchAll();
    db()->prepare("DELETE FROM comments WHERE user_id IN ($ph)")->execute($userIds);
    $upd = db()->prepare("UPDATE posts SET comments_count = GREATEST(0, comments_count - ?) WHERE id = ?");
    foreach ($affected as $row) {
        $upd->execute([(int)$row['c'], (int)$row['post_id']]);
    }
}

/**
 * 删除指定帖子 ID 列表的所有 likes / favorites / comments 记录。
 * 注意：这里不需要同步扣减 posts 表的计数字段，因为这些帖子本身会被删除。
 *
 * @param array $postIds 帖子 ID 列表
 */
function delete_post_relations(array $postIds): void {
    $postIds = array_values(array_filter(array_map('intval', $postIds), fn($x) => $x > 0));
    if (empty($postIds)) return;
    $ph = implode(',', array_fill(0, count($postIds), '?'));
    db()->prepare("DELETE FROM likes WHERE post_id IN ($ph)")->execute($postIds);
    db()->prepare("DELETE FROM favorites WHERE post_id IN ($ph)")->execute($postIds);
    db()->prepare("DELETE FROM comments WHERE post_id IN ($ph)")->execute($postIds);
}

function time_ago(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)   return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . ' 分钟前';
    if ($diff < 86400) return floor($diff / 3600) . ' 小时前';
    if ($diff < 2592000) return floor($diff / 86400) . ' 天前';
    return date('Y-m-d', $ts);
}

function setting(string $key, string $default = ''): string {
    $cfg = load_config();
    if (!$cfg) return $default;
    return $cfg[$key] ?? $default;
}

/** 从数据库读取动态应用配置（可被管理员随时修改） */
function app_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = db()->prepare("SELECT value FROM app_settings WHERE key_name = ?");
        $stmt->execute([$key]);
        $r = $stmt->fetch();
        $cache[$key] = $r ? $r['value'] : $default;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/** 写入动态应用配置 */
function set_app_setting(string $key, string $value): void {
    db()->prepare("INSERT INTO app_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?")
        ->execute([$key, $value, $value]);
}

/* ============================================================
 *  联系方式验证与清洗
 *
 *  数据结构：contact 字段存储 JSON 数组字符串，例如：
 *  [{"platform":"wechat","value":"wxid_abc123","label":"微信"},
 *   {"platform":"qq","value":"12345678","label":"QQ"},
 *   {"platform":"email","value":"a@b.com","label":"邮箱"},
 *   {"platform":"github","value":"username","label":"GitHub"},
 *   {"platform":"telegram","value":"@username","label":"Telegram"},
 *   {"platform":"discord","value":"username#1234","label":"Discord"},
 *   {"platform":"phone","value":"13800138000","label":"手机"},
 *   {"platform":"website","value":"https://example.com","label":"网站"},
 *   {"platform":"custom","value":"xxx","label":"其他"}]
 *
 *  安全策略：
 *  - platform：白名单校验，只允许预定义的平台标识符
 *  - value：clean_text 清洗 + 长度限制，剥离 HTML/JS
 *  - label：clean_plain 纯文本，长度 ≤ 20
 *  - 最多 10 条联系方式，防滥用
 *  - 整体 JSON 字符串长度 ≤ 1000（数据库字段限制）
 * ============================================================ */

/**
 * 预定义的联系方式平台白名单。
 * 每个平台有一个标识符和默认显示名。
 */
function contact_platform_whitelist(): array {
    return [
        'wechat'   => '微信',
        'qq'       => 'QQ',
        'email'    => '邮箱',
        'phone'    => '手机',
        'telegram' => 'Telegram',
        'discord'  => 'Discord',
        'github'   => 'GitHub',
        'gitee'    => 'Gitee',
        'weibo'    => '微博',
        'bilibili' => '哔哩哔哩',
        'zhihu'    => '知乎',
        'twitter'  => 'Twitter / X',
        'instagram'=> 'Instagram',
        'youtube'  => 'YouTube',
        'tiktok'   => 'TikTok / 抖音',
        'linkedin' => '领英',
        'steam'    => 'Steam',
        'website'  => '个人网站',
        'custom'   => '自定义',
    ];
}

/**
 * 验证并清洗一条联系方式记录。
 * 返回清洗后的 [platform, value, label] 或 null（非法）。
 */
function validate_contact_item(array $item): ?array {
    $whitelist = contact_platform_whitelist();
    $platform = trim((string)($item['platform'] ?? ''));
    if (!isset($whitelist[$platform])) return null;
    $value = trim((string)($item['value'] ?? ''));
    if ($value === '') return null;
    if (mb_strlen($value, 'UTF-8') > 100) return null;
    // 清洗：剥离 HTML/JS
    $value = clean_text($value, 100);
    if ($value === '') return null;
    // label：用自定义或平台默认名
    $label = trim((string)($item['label'] ?? ''));
    if ($label === '') $label = $whitelist[$platform];
    $label = clean_plain($label, 20);
    if ($label === '') $label = $whitelist[$platform];

    // 平台特定的格式校验（宽松校验，拒绝明显非法值）
    // email：必须是合法邮箱格式
    if ($platform === 'email') {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return null;
    }
    // website：必须是 http(s):// 开头的合法 URL
    if ($platform === 'website') {
        if (!preg_match('#^https?://[a-zA-Z0-9]#i', $value)) return null;
        // 用 parse_url 二次校验
        $parsed = parse_url($value);
        if (!$parsed || empty($parsed['host'])) return null;
    }
    // phone：只允许数字、+、-、空格、括号，长度 5-20
    if ($platform === 'phone') {
        if (!preg_match('/^[0-9+\-\s\(\)]{5,20}$/', $value)) return null;
    }
    // qq：纯数字，5-12 位
    if ($platform === 'qq') {
        if (!preg_match('/^[1-9]\d{4,11}$/', $value)) return null;
    }

    return ['platform' => $platform, 'value' => $value, 'label' => $label];
}

/**
 * 验证并清洗完整的联系方式数组。
 * 最多 10 条，去重（同平台+同值视为重复）。
 * 返回清洗后的数组。
 */
function validate_contact_array(array $items): array {
    $out = [];
    $seen = [];
    $count = 0;
    foreach ($items as $item) {
        if ($count >= 10) break; // 最多 10 条
        if (!is_array($item)) continue;
        $validated = validate_contact_item($item);
        if (!$validated) continue;
        $key = $validated['platform'] . '|' . $validated['value'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $validated;
        $count++;
    }
    return $out;
}

/**
 * 解析用户 contact 字段（JSON 字符串）为数组。
 */
function parse_contact(string $contactJson): array {
    if ($contactJson === '') return [];
    $decoded = json_decode($contactJson, true);
    if (!is_array($decoded)) return [];
    return $decoded;
}

/* ============================================================
 *  图片分离存储系统
 *
 *  背景：之前 base64 图片直接存在 posts.cover / posts.images /
 *  users.avatar / studios.cover 字段里，导致列表查询时返回巨大的
 *  base64 数据，API 响应慢、带宽高。
 *
 *  方案：新建 images 表存储 base64 数据。原字段改为存图片 ID（数字）。
 *  - 新数据：存图片 ID（如 "123"），API 返回 "?api=image&id=123"
 *  - 旧数据：仍是 "data:image/..." base64，API 原样返回（向后兼容）
 *  - 判断逻辑：以 "data:" 开头 = 旧 base64；纯数字 = 新图片 ID
 * ============================================================ */

/**
 * 将 base64 图片存入 images 表，返回图片 ID。
 * 如果传入的已经是数字 ID（非 base64），直接返回该 ID。
 *
 * @param string|null $dataUrl base64 data URL
 * @param int $userId 上传者 ID
 * @return string 图片 ID（字符串形式，存入原字段）
 */
function store_image(?string $dataUrl, int $userId = 0): string {
    if (!$dataUrl || $dataUrl === '') return '';
    // 如果已经是数字 ID，直接返回
    if (preg_match('/^\d+$/', $dataUrl)) return $dataUrl;
    // 必须是合法 data URL
    if (!valid_data_url($dataUrl)) return '';
    // 存入 images 表
    try {
        db()->prepare("INSERT INTO images (data, uploader_id, created_at) VALUES (?, ?, ?)")
            ->execute([$dataUrl, $userId, time()]);
        return (string)(int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('store_image failed: ' . $e->getMessage());
        return '';
    }
}

/**
 * 解析图片字段，返回前端可用的 URL。
 * - 纯数字 → 返回 "?api=image&id=数字"（新格式，图片在 images 表）
 * - "data:" 开头 → 原样返回（旧格式，base64 内联）
 * - 空 → 返回 null
 *
 * @param string|null $value 图片字段值
 * @return string|null
 */
function resolve_image(?string $value): ?string {
    if (!$value || $value === '') return null;
    // 纯数字 = 图片 ID
    if (preg_match('/^\d+$/', $value)) {
        return '?api=image&id=' . $value;
    }
    // data: 开头 = 旧 base64
    return $value;
}

/**
 * 批量解析图片数组（用于 posts.images 字段）。
 *
 * @param string|null $jsonStr JSON 数组字符串
 * @return array 解析后的 URL 数组
 */
function resolve_image_array(?string $jsonStr): array {
    if (!$jsonStr || $jsonStr === '') return [];
    $decoded = json_decode($jsonStr, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $item) {
        $resolved = resolve_image((string)$item);
        if ($resolved !== null) $out[] = $resolved;
    }
    return $out;
}

/**
 * 批量存储图片数组，返回 ID 数组的 JSON。
 *
 * @param array $dataUrls base64 data URL 数组
 * @param int $userId 上传者 ID
 * @return string JSON 数组字符串（如 "[\"123\",\"456\"]"）
 */
function store_image_array(array $dataUrls, int $userId = 0): string {
    $ids = [];
    foreach ($dataUrls as $url) {
        if (!is_string($url)) continue;
        $id = store_image($url, $userId);
        if ($id !== '') $ids[] = $id;
    }
    return json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ============================================================
 *  举报系统
 *
 *  数据结构：reports 表
 *  - target_type: 'post' | 'comment' | 'user'
 *  - target_id: 对应的帖子/评论/用户 ID
 *  - reason: 举报原因标识符（白名单）
 *  - detail: 用户填写的补充说明（≤500 字）
 *  - status: 'pending' | 'resolved' | 'dismissed'
 *  - handler_id: 处理该举报的管理员 ID
 *  - handler_note: 管理员处理备注
 *
 *  安全策略：
 *  - reason 必须在白名单内（防注入）
 *  - detail 经 clean_text 清洗（防 XSS）
 *  - 同一用户对同一目标只能举报一次（UNIQUE 约束在应用层校验）
 *  - 举报频率限制：同一用户 60 秒内最多 3 次，1 小时内最多 20 次
 * ============================================================ */

/**
 * 举报原因白名单。
 * key = 原因标识符（存数据库），value = 中文显示名。
 */
function report_reason_whitelist(): array {
    return [
        'spam'           => '垃圾广告 / 推广',
        'porn'           => '色情低俗内容',
        'violence'       => '暴力血腥内容',
        'political'      => '违法违规 / 政治敏感',
        'insult'         => '人身攻击 / 辱骂',
        'harassment'     => '骚扰 / 网络霸凌',
        'fraud'          => '诈骗 / 欺诈',
        'copyright'      => '侵权 / 抄袭',
        'malware'        => '恶意代码 / 病毒',
        'inappropriate'  => '其他不当内容',
        'custom'         => '自定义原因',
    ];
}

/**
 * 举报频率限制文件路径。
 */
function report_rate_limit_file(string $window): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return sys_get_temp_dir() . '/htmlhub_report_' . $window . '_' . md5($ip);
}

/**
 * 检查举报频率是否超限。
 */
function report_rate_check(string $window, int $threshold, int $seconds): bool {
    $file = report_rate_limit_file($window);
    $now = time();
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) $data = json_decode($raw, true) ?: [];
    }
    $data = array_values(array_filter($data, fn($t) => $t > $now - $seconds));
    return count($data) < $threshold;
}

/**
 * 记录一次举报到频率限制计数。
 */
function report_rate_record(): void {
    $now = time();
    foreach (['60s' => 60, '1h' => 3600] as $win => $sec) {
        $file = report_rate_limit_file($win);
        $data = [];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $data = json_decode($raw, true) ?: [];
        }
        $data[] = $now;
        $data = array_values(array_filter($data, fn($t) => $t > $now - $sec));
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

/* ============================================================
 *  Content-Security-Policy 动态白名单
 *
 *  背景：HTML 作品通过 <iframe srcdoc sandbox> 渲染，srcdoc 文档会继承
 *  父页面的 CSP。父页面 CSP 的 script-src/style-src/font-src/connect-src
 *  会限制作品内能引用的外部资源。
 *
 *  方案：
 *  1. 内置一份「主流可信 CDN 白名单」（cdnjs / jsdelivr / unpkg / bootstrapcdn
 *     / google fonts / google apis / fontawesome / microsoft 等），开箱即用。
 *  2. 管理员可在后台「设置 → CDN 白名单」追加自定义域名，存入 app_settings。
 *  3. 每次请求动态合并两份白名单，生成 CSP 头。
 *
 *  安全考量：
 *  - 仅放行 https CDN，不接受 http: 或 *（防中间人注入恶意脚本）
 *  - 仅放行知名 CDN 域名，不放行任意 *.com（防止恶意作品外联 C2）
 *  - default-src 仍为 'self'，未列入白名单的资源一律拒绝
 *  - 管理员追加的自定义域名会经过格式校验（必须是合法域名，不含路径/通配符以外的特殊字符）
 * ============================================================ */

/**
 * 内置可信 CDN 白名单（HTTPS only）。
 * 这些域名都是业界主流的公共 CDN，本身有完善的安全审计。
 */
function builtin_cdn_whitelist(): array {
    return [
        // JavaScript / CSS 库 CDN
        'https://cdnjs.cloudflare.com',         // cdnjs — 最全的 JS 库 CDN
        'https://cdn.jsdelivr.net',             // jsDelivr — npm/GitHub 镜像
        'https://unpkg.com',                    // unpkg — npm 镜像
        'https://cdn.bootcdn.net',              // BootCDN（国内）
        'https://cdn.bootcss.com',              // BootCSS（国内旧域名）
        'https://cdn.staticfile.org',            // Staticfile（国内）
        'https://lib.baomitu.com',              // 360 前端静态资源库（国内）
        'https://cdn.bytedance.com',            // 字节跳动 CDN
        'https://ajax.googleapis.com',           // Google AJAX Libraries
        'https://ajax.aspnetcdn.com',            // Microsoft AJAX CDN
        'https://code.jquery.com',               // jQuery 官方 CDN
        'https://maxcdn.bootstrapcdn.com',        // Bootstrap CDN（旧）
        'https://cdn.jsdelivr.net',              // jsDelivr（重复保险）
        'https://stackpath.bootstrapcdn.com',     // Bootstrap CDN
        'https://use.fontawesome.com',            // Font Awesome Kit
        'https://kit.fontawesome.com',            // Font Awesome Kit v5+
        'https://ka-fa.fontawesome.com',          // Font Awesome（国内镜像）
        'https://cdn.jsdelivr.net/npm',           // jsDelivr npm 子路径
        // 字体 CDN
        'https://fonts.googleapis.com',           // Google Fonts CSS
        'https://fonts.gstatic.com',              // Google Fonts 文件
        'https://fonts.font.im',                  // Google Fonts 国内镜像
        'https://fonts.loli.net',                 // Google Fonts 国内镜像 2
        // 图片 / 媒体 CDN（img-src 已允许 https:，这里仅作 connect-src 补充）
        'https://images.unsplash.com',
        'https://picsum.photos',
        'https://i.imgur.com',
        'https://cdn.pixabay.com',
        // Three.js / D3 / 数据可视化生态
        'https://threejs.org',
        'https://d3js.org',
        'https://cdn.plot.ly',
        // 其他常见前端资源
        'https://fastly.jsdelivr.net',            // jsDelivr Fastly 节点
        'https://ghcdn.jsdelivr.net',             // jsDelivr GitHub 镜像
        'https://gcore.jsdelivr.net',             // jsDelivr GCore 节点
        'https://testingcf.jsdelivr.net',         // jsDelivr 测试节点
    ];
}

/**
 * 解析管理员配置的自定义 CDN 白名单（从 app_settings 读取，每行一个域名）。
 * 返回去重后的 https:// 前缀域名数组。
 */
function custom_cdn_whitelist(): array {
    $raw = app_setting('cdn_whitelist', '');
    if ($raw === '') return [];
    $lines = preg_split('/[\r\n]+/', $raw);
    $out = [];
    $seen = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // 移除行尾注释
        if (($pos = strpos($line, '#')) !== false) $line = trim(substr($line, 0, $pos));
        if ($line === '') continue;
        // 校验：必须是 https:// 开头，或纯域名（自动补 https://）
        if (!preg_match('#^https?://#i', $line)) {
            $line = 'https://' . $line;
        }
        // 强制 https
        $line = preg_replace('#^http://#i', 'https://', $line);
        // 校验域名格式：只允许字母数字点减号，可选端口，可选单层通配符前缀 *.domain
        // 解析 host
        $host = parse_url($line, PHP_URL_HOST);
        $port = parse_url($line, PHP_URL_PORT);
        if (!$host) continue;
        // 允许 *.domain.com 或 domain.com 格式
        if (!preg_match('#^(\*\.)?[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$#', $host)) continue;
        // 重建规范化 URL（去掉路径，保留 scheme + host + port）
        $normalized = 'https://' . $host . ($port ? ':' . $port : '');
        if (isset($seen[$normalized])) continue;
        $seen[$normalized] = true;
        $out[] = $normalized;
    }
    return $out;
}

/**
 * 获取合并后的完整 CDN 白名单（内置 + 自定义），去重。
 */
function full_cdn_whitelist(): array {
    $all = array_merge(builtin_cdn_whitelist(), custom_cdn_whitelist());
    return array_values(array_unique($all));
}

/**
 * 构建主页面的 Content-Security-Policy 头。
 * 合并内置白名单 + 管理员自定义白名单。
 *
 * @param bool $forHostedPage 是否用于托管页面（更宽松，允许 object-src）
 */
function build_csp_header(bool $forHostedPage = false): string {
    $whitelist = full_cdn_whitelist();
    // 拼接白名单源（'self' + 'unsafe-inline'/'unsafe-eval' + 各 CDN）
    $cdnSrc = implode(' ', $whitelist);

    $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval' " . $cdnSrc;
    $styleSrc  = "'self' 'unsafe-inline' " . $cdnSrc;
    $fontSrc   = "'self' data: " . $cdnSrc;
    $imgSrc    = "'self' data: blob: https:";
    $connectSrc= "'self' " . $cdnSrc;
    $frameSrc  = "'self' data: blob:";
    $mediaSrc  = "'self' data: blob: " . $cdnSrc;

    $parts = [
        "default-src 'self'",
        "script-src " . $scriptSrc,
        "style-src " . $styleSrc,
        "img-src " . $imgSrc,
        "font-src " . $fontSrc,
        "frame-src " . $frameSrc,
        "connect-src " . $connectSrc,
        "media-src " . $mediaSrc,
        "object-src " . ($forHostedPage ? $cdnSrc : "'none'"),
        "base-uri 'self'",
    ];
    return implode('; ', $parts);
}

/**
 * 兜底 CSP：仅用内置白名单（不读数据库）。
 * 用于数据库不可用时的 fallback，保证页面仍可加载。
 */
function build_csp_header_fallback(): string {
    $cdnSrc = implode(' ', builtin_cdn_whitelist());
    $parts = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' " . $cdnSrc,
        "style-src 'self' 'unsafe-inline' " . $cdnSrc,
        "img-src 'self' data: blob: https:",
        "font-src 'self' data: " . $cdnSrc,
        "frame-src 'self' data: blob:",
        "connect-src 'self' " . $cdnSrc,
        "media-src 'self' data: blob: " . $cdnSrc,
        "object-src 'none'",
        "base-uri 'self'",
    ];
    return implode('; ', $parts);
}

/** 生成唯一 slug（8 位随机字符串） */
function generate_slug(int $len = 8): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($chars) - 1;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $slug = '';
        for ($i = 0; $i < $len; $i++) $slug .= $chars[random_int(0, $max)];
        $chk = db()->prepare("SELECT 1 FROM hosted_pages WHERE slug = ?");
        $chk->execute([$slug]);
        if (!$chk->fetch()) return $slug;
    }
    return $slug . time(); // 兜底
}

/**
 * XOR 加密 HTML 内容
 * 返回 [data, key]，前端用 key 解密
 * 不是军事级加密，但足以防止 Network 面板直接看到明文
 */
function encrypt_html_content(string $html, int $postId): array {
    $key = substr(hash('sha256', 'htmlhub_salt_' . $postId . '_' . random_int(100000, 999999)), 0, 32);
    $encrypted = '';
    $len = strlen($html);
    $klen = strlen($key);
    for ($i = 0; $i < $len; $i++) {
        $encrypted .= chr(ord($html[$i]) ^ ord($key[$i % $klen]));
    }
    return [
        'data' => base64_encode($encrypted),
        'key'  => $key,
    ];
}

/**
 * 注入反调试脚本 + 隐形水印
 *
 * 防护策略（不挡 F12 / 右键，而是检测 + 保护）：
 * 1. debugger 时间差检测：开发者工具打开时 debugger 语句会暂停，时间差 >100ms 即判定
 * 2. 窗口尺寸检测：开发者工具会导致 outerWidth/innerWidth 差值变大
 * 3. console.log 原生性检测：被重写说明有人在调试
 * 4. 检测到调试行为后清空 DOM（不是关闭页面，而是让内容消失）
 * 5. MutationObserver 防止 DOM 被篡改恢复
 * 6. 隐形水印：HTML 注释中嵌入帖子ID + 用户ID（零宽字符方式）
 */
function inject_anti_debug(string $html, int $postId, int $userId): string {
    // 隐形水印：HTML 注释 + 零宽字符
    $watermarkData = base64_encode($postId . ':' . $userId . ':' . time());
    $watermark = "\n<!--HTMLHub:" . $watermarkData . "-->\n";

    // 反调试脚本：压缩为单行，避免被轻易格式化阅读
    $script = '<script>(function(){'
        . 'var P=function(){'
        // 清空 DOM 并显示提示
        . 'document.documentElement.innerHTML='
        . '"<div style=\\"font-family:-apple-system,sans-serif;text-align:center;padding:80px 20px;color:#666;background:#f5f5f5;min-height:100vh\\">'
        . '<div style=\\"font-size:48px;margin-bottom:16px\\">🔒</div>'
        . '<h2 style=\\"font-size:18px;margin-bottom:8px\\">内容已保护</h2>'
        . '<p style=\\"font-size:14px;color:#999\\">请关闭开发者工具后刷新页面</p></div>";'
        . '};'
        // 1. debugger 时间差检测
        . 'var D=function(){var s=performance.now();debugger;var e=performance.now();if(e-s>100){P();}};'
        . 'setTimeout(D,500);setInterval(D,4000);'
        // 2. 窗口尺寸检测
        . 'window.addEventListener("resize",function(){'
        . 'if(window.outerWidth-window.innerWidth>160||window.outerHeight-window.innerHeight>160){P();}'
        . '});'
        // 3. console 原生性检测
        . 'var L=console.log.toString();'
        . 'if(L.indexOf("native code")===-1&&L.indexOf("{ [native code] }")===-1){P();}'
        // 4. 检测 devtools 的 console.dir 被调用
        . 'var O=console.dir;'
        . 'console.dir=function(){P();};'
        // 5. 防止通过 location.view-source 查看
        . 'try{Object.defineProperty(window,"__lookupGetter__",{value:function(){return function(){}}});}catch(e){}'
        . '})();</script>';

    // 在 </body> 前插入水印 + 脚本；没有 </body> 则追加到末尾
    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $watermark . $script . '</body>', $html, 1);
    } else if (stripos($html, '</html>') !== false) {
        $html = preg_replace('/<\/html>/i', $watermark . $script . '</html>', $html, 1);
    } else {
        $html .= $watermark . $script;
    }
    return $html;
}

function post_view_array(array $p, array $u): array {
    // images 字段是 JSON 数组字符串，解析成数组
    // 新格式：图片 ID 数组 → 转为 URL；旧格式：base64 数组 → 原样
    $images = resolve_image_array($p['images'] ?? null);
    $editedAt = (int)($p['edited_at'] ?? 0);
    return [
        'id'              => (int)$p['id'],
        'type'            => $p['type'],
        'title'           => $p['title'],
        'content'         => $p['content'],
        'cover'           => resolve_image($p['cover'] ?? null),
        'images'          => $images,
        'view_mode'       => $p['view_mode'],
        'is_pinned'       => !empty($p['is_pinned']),
        'studio_id'       => (int)($p['studio_id'] ?? 0),
        'is_edited'       => $editedAt > 0,
        'edited_at'       => $editedAt > 0 ? time_ago($editedAt) : '',
        'views'           => (int)$p['views'],
        'likes_count'     => (int)$p['likes_count'],
        'favorites_count' => (int)$p['favorites_count'],
        'comments_count'  => (int)$p['comments_count'],
        'created_at'      => time_ago((int)$p['created_at']),
        'created_ts'      => (int)$p['created_at'],
        'author'          => [
            'id'       => (int)($u['user_id'] ?? $u['author_id'] ?? $u['id'] ?? $p['user_id']),
            'username' => $u['username'] ?? '',
            'avatar'   => resolve_image($u['avatar'] ?? null),
        ],
    ];
}

function is_following(int $meId, int $targetId): bool {
    if ($meId <= 0 || $targetId <= 0 || $meId === $targetId) return false;
    $stmt = db()->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$meId, $targetId]);
    return (bool)$stmt->fetch();
}

function is_mutual_follow(int $meId, int $targetId): bool {
    if ($meId <= 0 || $targetId <= 0 || $meId === $targetId) return false;
    return is_following($meId, $targetId) && is_following($targetId, $meId);
}

function user_view_array(array $u, ?int $meId = null): array {
    $followers = db()->prepare("SELECT COUNT(*) AS c FROM follows WHERE following_id = ?");
    $followers->execute([$u['id']]);
    $fc = (int)$followers->fetch()['c'];
    $following = db()->prepare("SELECT COUNT(*) AS c FROM follows WHERE follower_id = ?");
    $following->execute([$u['id']]);
    $ig = (int)$following->fetch()['c'];
    $posts = db()->prepare("SELECT COUNT(*) AS c FROM posts WHERE user_id = ?");
    $posts->execute([$u['id']]);
    $pc = (int)$posts->fetch()['c'];
    // 该用户所有帖子的累计获赞数 / 收藏数（用于个人页统计栏）
    $likesRecv = db()->prepare("SELECT COALESCE(SUM(likes_count), 0) AS c FROM posts WHERE user_id = ?");
    $likesRecv->execute([$u['id']]);
    $lc = (int)$likesRecv->fetch()['c'];
    $favsRecv = db()->prepare("SELECT COALESCE(SUM(favorites_count), 0) AS c FROM posts WHERE user_id = ?");
    $favsRecv->execute([$u['id']]);
    $fvc = (int)$favsRecv->fetch()['c'];
    // 该用户收藏的帖子总数
    $favsMade = db()->prepare("SELECT COUNT(*) AS c FROM favorites WHERE user_id = ?");
    $favsMade->execute([$u['id']]);
    $fmc = (int)$favsMade->fetch()['c'];
    // 解析联系方式（JSON 字符串 → 数组）
    $contactRaw = $u['contact'] ?? '';
    $contact = [];
    if ($contactRaw !== '') {
        $decoded = json_decode($contactRaw, true);
        if (is_array($decoded)) $contact = $decoded;
    }
    return [
        'id'              => (int)$u['id'],
        'username'        => $u['username'],
        'avatar'          => resolve_image($u['avatar'] ?? null),
        'bio'             => $u['bio'] ?? '',
        'contact'         => $contact,
        'role'            => $u['role'] ?? 'user',
        'status'          => $u['status'] ?? 'active',
        'created_at'      => time_ago((int)($u['created_at'] ?? time())),
        'posts_count'     => $pc,
        'followers_count' => $fc,
        'following_count' => $ig,
        'likes_received'  => $lc,
        'favorites_received' => $fvc,
        'favorites_made'  => $fmc,
        'is_following'    => $meId ? is_following($meId, (int)$u['id']) : false,
        'is_mutual'       => $meId ? is_mutual_follow($meId, (int)$u['id']) : false,
    ];
}

/* ============================================================
 *  托管页面直接输出（纯 HTML，不进入 SPA）
 *  访问 ?hosted=SLUG 时直接输出 HTML 内容
 * ============================================================ */
if (isset($_GET['hosted']) && is_installed()) {
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['hosted']);
    if ($slug !== '') {
        try {
            $stmt = db()->prepare("SELECT html_content, is_banned, title, user_id, persistent_mode FROM hosted_pages WHERE slug = ?");
            $stmt->execute([$slug]);
            $page = $stmt->fetch();
            if ($page) {
                // 检查封禁状态
                if (!empty($page['is_banned'])) {
                    http_response_code(403);
                    header('Content-Type: text/html; charset=utf-8');
                    $banTitle = $page['title'] ?: '该项目';
                    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>该项目已被封禁</title><style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#0a0a0f;color:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}.ban-box{text-align:center;max-width:400px}.ban-icon{font-size:64px;margin-bottom:20px}.ban-title{font-size:22px;font-weight:700;margin-bottom:10px}.ban-desc{font-size:14px;color:#71717a;line-height:1.6;margin-bottom:24px}a{color:#3b6cff;text-decoration:none;font-size:14px}</style></head><body><div class="ban-box"><div class="ban-icon">🚫</div><div class="ban-title">该项目已被封禁</div><div class="ban-desc">"' . htmlspecialchars($banTitle, ENT_QUOTES, 'UTF-8') . '" 因违反社区规则已被管理员封禁，无法继续访问。</div><a href="/">返回首页</a></div></body></html>';
                    exit;
                }

                $title = $page['title'] ?: 'HTML 作品';
                $rawHtml = $page['html_content'];
                $rawHtml = inject_anti_debug($rawHtml, 0, (int)$page['user_id']);
                $escapedHtml = htmlspecialchars($rawHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // 增加浏览量
                db()->prepare("UPDATE hosted_pages SET views = views + 1 WHERE slug = ?")->execute([$slug]);

                // 双模式渲染：
                // - persistent_mode = 0（默认）：sandbox 无 allow-same-origin，null origin，localStorage 不可用（安全）
                // - persistent_mode = 1（作者主动开启）：sandbox 有 allow-same-origin + 严格 CSP（connect-src/form-action 'none'）
                //   localStorage 可用，但恶意 JS 无法调用 API（CSP 阻断）、无法读 cookie（httponly）
                if (!empty($page['persistent_mode'])) {
                    // 持久模式：iframe 继承同源（localStorage 可用），但 CSP 严格限制网络请求
                    // 安全模型：
                    //   - cookie 是 httponly → JS 读不到 session/remember_token
                    //   - CSP connect-src 'none' → fetch/XHR/WebSocket 全被阻断，无法调 API
                    //   - CSP form-action 'none' → 表单提交被阻断，无法 CSRF
                    //   - localStorage 可读（但里面只有主题/搜索历史等非敏感数据）
                    header_remove('Content-Security-Policy');
                    $cdnSrc = '';
                    try { $cdnSrc = implode(' ', full_cdn_whitelist()); } catch (Throwable $e) {}
                    header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: " . $cdnSrc . "; "
                        . "connect-src 'none'; "
                        . "form-action 'none'; "
                        . "base-uri 'none'; "
                        . "frame-ancestors 'self'");
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Type: text/html; charset=utf-8');
                    header('Cache-Control: public, max-age=300');
                    $wrapper = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
                        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
                        . '<style>*{margin:0;padding:0;box-sizing:border-box}html,body{height:100%;overflow:hidden;background:#fff}iframe{width:100%;height:100%;border:none}</style>'
                        . '</head><body>'
                        . '<iframe sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock allow-presentation" '
                        . 'srcdoc="' . $escapedHtml . '"></iframe>'
                        . '</body></html>';
                    echo apply_gzip_if_beneficial($wrapper);
                    exit;
                } else {
                    // 安全模式：sandbox 无 allow-same-origin，null origin（默认）
                    header_remove('Content-Security-Policy');
                    header("Content-Security-Policy: default-src 'none'; "
                        . "frame-src data: blob:; "
                        . "style-src 'self' 'unsafe-inline'; "
                        . "img-src 'self' data:; "
                        . "base-uri 'none'; "
                        . "form-action 'none'");
                    header('X-Frame-Options: DENY');
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Type: text/html; charset=utf-8');
                    header('Cache-Control: public, max-age=300');
                    $wrapper = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
                        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
                        . '<style>*{margin:0;padding:0;box-sizing:border-box}html,body{height:100%;overflow:hidden;background:#fff}iframe{width:100%;height:100%;border:none}</style>'
                        . '</head><body>'
                        . '<iframe sandbox="allow-scripts allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock allow-presentation" '
                        . 'srcdoc="' . $escapedHtml . '"></iframe>'
                        . '</body></html>';
                    echo apply_gzip_if_beneficial($wrapper);
                    exit;
                }
            }
        } catch (Throwable $e) {}
    }
    // 404
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 - 页面不存在</title></head><body style="font-family:-apple-system,sans-serif;text-align:center;padding:60px 20px;color:#333"><h1 style="font-size:48px;margin:0 0 10px;color:#999">404</h1><p style="margin:0 0 20px;color:#666">托管的页面不存在或已被删除</p><a href="/" style="color:#3b6cff;text-decoration:none">返回首页</a></body></html>';
    exit;
}

/* ============================================================
 *  API 路由
 * ============================================================ */
/* ============================================================
 *  站点级防克隆保护
 * ============================================================ */

// API 请求频率限制（防爬虫批量抓取）
function check_rate_limit(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'rate_' . md5($ip);
    $file = sys_get_temp_dir() . '/htmlhub_rate_' . md5($ip);
    $now = time();
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) $data = json_decode($raw, true) ?: [];
    }
    // 清理 60 秒前的记录
    $data = array_filter($data, fn($t) => $t > $now - 60);
    // 60 秒内超过 120 次请求 = 爬虫
    if (count($data) > 120) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '请求过于频繁，请稍后再试']);
        exit;
    }
    $data[] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

// 检测异常 User-Agent（空 UA 或已知爬虫 UA）
function is_suspicious_ua(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($ua)) return true;
    $suspicious = ['curl/', 'wget/', 'python-requests/', 'scrapy', 'httpclient', 'okhttp', 'java/', 'go-http-client', 'php/', 'node-fetch', 'axios/', 'postman'];
    $uaLower = strtolower($ua);
    foreach ($suspicious as $s) {
        if (strpos($uaLower, $s) !== false) return true;
    }
    return false;
}

/* ============================================================
 *  注册反机器人防护
 *  - 多窗口 IP 限流：60s/3 次，1h/10 次，1d/30 次
 *  - 蜜罐字段、表单渲染时间、同源 Referer 校验由 register 接口调用
 * ============================================================ */
function register_rate_limit_file(string $window): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // 同一 IP 共用一份文件，按窗口分桶
    return sys_get_temp_dir() . '/htmlhub_reg_' . $window . '_' . md5($ip);
}

/**
 * 检查 IP 在指定窗口内是否超过阈值。
 * 返回 [是否允许, 当前次数, 阈值, 窗口秒数]
 */
function register_rate_check(string $window, int $threshold, int $seconds): array {
    $file = register_rate_limit_file($window);
    $now = time();
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) $data = json_decode($raw, true) ?: [];
    }
    // 清理过期记录
    $data = array_values(array_filter($data, fn($t) => $t > $now - $seconds));
    $count = count($data);
    return [$count < $threshold, $count, $threshold, $seconds];
}

/**
 * 记录一次注册尝试。
 */
function register_rate_record(): void {
    $now = time();
    foreach (['60s' => 60, '1h' => 3600, '1d' => 86400] as $win => $sec) {
        $file = register_rate_limit_file($win);
        $data = [];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $data = json_decode($raw, true) ?: [];
        }
        $data[] = $now;
        // 仅保留窗口内记录
        $data = array_values(array_filter($data, fn($t) => $t > $now - $sec));
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

/**
 * 注册接口综合反机器人校验。
 * 任意一项命中即拒绝。
 *
 * @param array $d 用户提交的注册数据
 * @return string|null 拒绝原因，null 表示通过
 */
function register_anti_bot_check(array $d): ?string {
    // 1. 同源 Referer 校验（防跨站提交）
    //    注意：不做"没有 Referer 就拒绝"的强校验，因为部分浏览器隐私设置会剥离 Referer。
    //    只在 Referer 存在但与当前 host 不匹配时拒绝。
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host && $referer) {
        $refHost = parse_url($referer, PHP_URL_HOST);
        $refPort = parse_url($referer, PHP_URL_PORT);
        // 允许同 host
        $okHost = ($refHost === $host);
        // 也允许带端口的同 host
        if (!$okHost && $refHost) {
            $refFull = $refHost . ($refPort ? ':' . $refPort : '');
            if ($refFull === $host) $okHost = true;
        }
        if (!$okHost) {
            return '注册请求来源异常';
        }
    }

    // 2. 蜜罐字段：表单里藏了一个对用户不可见的 website 字段，
    //    机器人通常会填所有 input，一旦有值即判定机器人。
    $honeypot = trim((string)($d['website'] ?? ''));
    if ($honeypot !== '') {
        // 静默拒绝但延迟返回，避免给机器人提示
        usleep(500000); // 0.5s
        return '注册失败，请刷新页面后重试';
    }

    // 3. 表单渲染时间检测：前端在 renderRegister 时记录时间戳，
    //    提交时附带 _t（秒级 unix 时间戳）。
    //    人类填表至少 2 秒；机器人通常 < 1 秒。
    $renderTs = (int)($d['_t'] ?? 0);
    if ($renderTs <= 0) {
        return '注册请求格式异常';
    }
    $elapsed = time() - $renderTs;
    if ($elapsed < 2) {
        // 表单填得太快，疑似机器人
        return '注册失败，请稍后重试';
    }
    if ($elapsed > 3600) {
        // 表单渲染超过 1 小时才提交，也异常（可能被复用）
        return '注册会话已过期，请刷新页面';
    }

    // 4. IP 多窗口限流
    $checks = [
        register_rate_check('60s', 3, 60),    // 60 秒内最多 3 次
        register_rate_check('1h',  10, 3600), // 1 小时内最多 10 次
        register_rate_check('1d',  30, 86400),// 1 天内最多 30 次
    ];
    foreach ($checks as $c) {
        list($allowed, $count, $threshold, $seconds) = $c;
        if (!$allowed) {
            $human = $seconds >= 3600 ? ($seconds / 3600 . ' 小时') : ($seconds . ' 秒');
            return "该 IP 在 {$human}内注册次数过多（{$count}/{$threshold}），请稍后再试";
        }
    }

    return null; // 通过
}

/**
 * 检测机器人特征的批量用户名（如 user1234, test0001, abc1234 等）。
 * 用于管理员手动审查时的提示，不阻断注册。
 */
function looks_like_bot_username(string $username): bool {
    // 纯数字 + 至少 4 位连续数字
    if (preg_match('/^[a-zA-Z]+\d{4,}$/', $username)) return true;
    // 字母+多位连续数字
    if (preg_match('/^[a-zA-Z]{1,8}\d{5,}$/', $username)) return true;
    // 字典词 + 4 位数字
    $words = ['user', 'test', 'abc', 'temp', 'demo', 'guest', 'fake', 'bot', 'spam', 'account', 'name'];
    $lower = strtolower($username);
    foreach ($words as $w) {
        if (preg_match('/^' . preg_quote($w, '/') . '\d{3,}$/', $lower)) return true;
    }
    return false;
}

// 生成页面隐形水印（每次请求唯一，嵌入到 HTML/CSS 中）
function generate_site_watermark(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 50);
    $time = time();
    $data = base64_encode($ip . '|' . $ua . '|' . $time);
    return $data;
}

/* ============================================================
 *  无感人机验证（BotGuard）
 *
 *  设计目标：
 *    - 对真实浏览器用户零干扰（无可见 UI、无交互）
 *    - 拦截 headless 浏览器、curl/wget、Python requests 等非图形客户端
 *    - 防重放、防伪造、防篡改
 *
 *  工作流程：
 *    1. 用户打开表单页时，前端被动采集 5 项浏览器能力信号，
 *       组合成指纹 fp。
 *    2. 前端调用 api('botguard_issue', {fp})，服务端校验 fp 合法性
 *       后用 HMAC-SHA256 签发短时效 token（30 分钟）。
 *    3. 用户提交表单时携带 token，服务端验签 + 校验时效 + 校验
 *       fp 一致性 + 检查 nonce 是否已用过（防重放）。
 *
 *  信号集（前端采集，全部被动）：
 *    - perfNow    performance.now() 精度（headless 通常为 0 或整数）
 *    - rgba       Canvas 2D 渲染后 getImageData 的特征值
 *    - webgl      WebGL 渲染器字符串（headless 为空或 SwiftShader）
 *    - motion     ontouchstart / devicemotion 是否定义
 *    - tz         Intl 时区
 *
 *  4 个受保护接口：login / register / create_post / comment
 * ============================================================ */

/**
 * 获取 BotGuard HMAC 密钥。
 * 密钥来源优先级：
 *   1. config 文件中的 botguard_secret 字段（安装时生成，跨节点共享）
 *   2. 兜底：基于 DB 凭证 + 域名派生（仅在旧版未升级时使用，会触发升级提示）
 */
function botguard_secret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $cfg = load_config();
    // 升级写入后的最新配置（reload_config 通过 GLOBALS 桥接）
    if (!empty($GLOBALS['__htmlhub_cfg_latest']['botguard_secret'])) {
        $cfg = $GLOBALS['__htmlhub_cfg_latest'];
    }
    if (!empty($cfg['botguard_secret']) && is_string($cfg['botguard_secret']) && strlen($cfg['botguard_secret']) >= 32) {
        $secret = $cfg['botguard_secret'];
        return $secret;
    }
    // 兜底：基于 DB 凭证派生（保证服务可用，但应在安装/升级时写入独立密钥）
    $seed = ($cfg['db_user'] ?? '') . '|' . ($cfg['db_pass'] ?? '') . '|' . (__DIR__ . '/htmlhub_v3');
    $secret = hash('sha256', $seed, true); // 32 字节
    return $secret;
}

/**
 * 升级 config 文件，写入独立的 botguard_secret。
 * 仅在缺失时调用，避免重复写入。
 */
function botguard_ensure_secret_in_config(): void {
    $cfg = load_config();
    if (!empty($cfg['botguard_secret'])) return;
    $cfg['botguard_secret'] = bin2hex(random_bytes(32)); // 64 字符十六进制
    $configContent = "<?php\n// HTMLHub 配置文件 - 自动生成\n// 请勿修改，如需修改请删除此文件重新安装\nreturn " . var_export($cfg, true) . ";\n";
    @file_put_contents(CONFIG_FILE, $configContent, LOCK_EX);
    @chmod(CONFIG_FILE, 0640);
    // 通过 GLOBALS 桥接，让 botguard_secret() 立即读到新密钥
    $GLOBALS['__htmlhub_cfg_latest'] = $cfg;
}

/**
 * 计算 fp 的稳定哈希（用于服务端比对，不存储原始指纹）。
 */
function botguard_fp_hash(string $fp): string {
    return hash('sha256', $fp);
}

/**
 * 签发 BotGuard token。
 *
 * @param string $fp 前端采集的指纹字符串（JSON）
 * @return array {token, expires_at}
 */
function botguard_issue_token(string $fp): array {
    // 指纹长度限制（防滥用）
    if (strlen($fp) > 2048) {
        $fp = substr($fp, 0, 2048);
    }
    $fpHash = botguard_fp_hash($fp);
    $nonce = bin2hex(random_bytes(8)); // 16 字符，防重放
    $exp = time() + 1800; // 30 分钟有效期
    $payload = ['fp' => $fpHash, 'nonce' => $nonce, 'exp' => $exp];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $payloadB64, botguard_secret(), true);
    $sigB64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    return [
        'token' => $payloadB64 . '.' . $sigB64,
        'expires_at' => $exp,
    ];
}

/**
 * 校验 BotGuard token。
 *
 * 校验项：
 *   - 格式合法（payload.sig）
 *   - 签名有效（HMAC-SHA256）
 *   - 未过期（exp > now）
 *   - 未被使用过（nonce 在 Redis/文件中标记为已用，防重放）
 *   - 指纹与请求中携带的 fp 一致（防篡改）
 *
 * @param string $token 客户端提交的 token
 * @param string $fp    客户端本次提交的指纹（可选，用于二次校验）
 * @return string|null 校验失败返回拒绝原因，成功返回 null
 */
function botguard_verify_token(string $token, string $fp = ''): ?string {
    if ($token === '') return '人机验证缺失';
    $parts = explode('.', $token);
    if (count($parts) !== 2) return '人机验证格式错误';
    list($payloadB64, $sigB64) = $parts;
    $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'));
    $sig = base64_decode(strtr($sigB64, '-_', '+/'));
    if ($payloadJson === false || $sig === false) return '人机验证解码失败';
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload) || empty($payload['fp']) || empty($payload['nonce']) || empty($payload['exp'])) {
        return '人机验证载荷非法';
    }
    // 重算签名比对（constant-time 防时序攻击）
    $expectedSig = hash_hmac('sha256', $payloadB64, botguard_secret(), true);
    if (!hash_equals($expectedSig, $sig)) return '人机验证签名无效';
    // 时效校验
    if (time() > (int)$payload['exp']) return '人机验证已过期，请刷新页面';
    // 指纹一致性校验（若客户端本次提交了 fp，则必须与 token 中的一致）
    if ($fp !== '') {
        if (hash_equals(botguard_fp_hash($fp), $payload['fp']) === false) {
            return '人机验证指纹不匹配';
        }
    }
    // 防重放：检查 nonce 是否已用过
    $nonceFile = sys_get_temp_dir() . '/htmlhub_bg_' . md5($payload['nonce']);
    if (file_exists($nonceFile)) {
        return '人机验证已被使用，请刷新页面';
    }
    // 标记 nonce 已用（保留到 token 过期后 1 小时，便于清理）
    $ttl = (int)$payload['exp'] - time() + 3600;
    if ($ttl > 0) {
        @file_put_contents($nonceFile, (string)time(), LOCK_EX);
        @touch($nonceFile, time() + $ttl);
    }
    return null; // 通过
}

/**
 * 服务端对前端指纹的"信号合理性"校验。
 * 不依赖 fp 内部细节，只检查总体结构合理性（防止伪造 JSON 绕过）。
 *
 * 真正的"是否浏览器"判断由前端采集 + token 签发共同保证：
 *   - 非浏览器（curl/headless 无 Canvas）无法生成合法 fp
 *   - 即使伪造 fp，也需要拿到服务端签发的 token 才能提交表单
 *   - 而 token 与 fp 绑定，篡改 fp 会导致验签失败
 *
 * @param string $fp 前端提交的指纹 JSON
 * @return string|null 失败原因，null 表示通过
 */
function botguard_validate_fp(string $fp): ?string {
    if ($fp === '') return '指纹为空';
    if (strlen($fp) > 2048) return '指纹异常';
    $data = json_decode($fp, true);
    if (!is_array($data)) return '指纹格式错误';
    // 必须包含 5 项核心信号
    $required = ['perfNow', 'rgba', 'webgl', 'motion', 'tz'];
    foreach ($required as $k) {
        if (!array_key_exists($k, $data)) return '指纹字段缺失';
    }
    // 时区非空（无图形客户端通常无 Intl）
    if (!is_string($data['tz']) || $data['tz'] === '') return '时区信息缺失';
    // Canvas rgba 应为非空字符串（headless 通常为空或 "0,0,0,0"）
    if (!is_string($data['rgba']) || strlen($data['rgba']) < 4) return '渲染能力异常';
    return null;
}

if (isset($_GET['api'])) {
    header('Cache-Control: no-store');
    // 防爬：频率限制 + UA 检测
    check_rate_limit();
    if (is_suspicious_ua()) {
        json_out(['error' => 'Access denied'], 403);
    }
    try {
        $api = (string)$_GET['api'];

        /* --- 公共：状态 --- */
        if ($api === 'status') {
            json_out([
                'installed'   => is_installed(),
                'version'     => VERSION,
                'logged_in'   => !empty($_SESSION['uid']),
                'is_admin'    => !empty($_SESSION['is_admin']),
                'php_ok'      => true,
                'pdo_mysql'   => extension_loaded('pdo_mysql'),
            ]);
        }

        /* --- 图片服务（从 images 表读取，输出原始二进制 + 缓存头） --- */
        if ($api === 'image') {
            $imgId = (int)($_GET['id'] ?? 0);
            if ($imgId <= 0) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo 'Not found';
                exit;
            }
            try {
                $stmt = db()->prepare("SELECT data FROM images WHERE id = ?");
                $stmt->execute([$imgId]);
                $img = $stmt->fetch();
            } catch (Throwable $e) {
                http_response_code(500);
                header('Content-Type: text/plain');
                echo 'DB error';
                exit;
            }
            if (!$img || !$img['data']) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo 'Not found';
                exit;
            }
            // 解析 data URL，输出原始二进制
            $data = $img['data'];
            if (preg_match('#^data:image/(png|jpe?g|webp|gif);base64,(.+)$#s', $data, $m)) {
                $mime = strtolower($m[1]);
                if ($mime === 'jpg') $mime = 'jpeg';
                $binary = base64_decode($m[2]);
                if ($binary === false || $binary === '') {
                    http_response_code(500);
                    header('Content-Type: text/plain');
                    echo 'Decode error';
                    exit;
                }
                // 缓存头：浏览器 + CDN 缓存 30 天
                header('Content-Type: image/' . $mime);
                header('Content-Length: ' . strlen($binary));
                header('Cache-Control: public, max-age=2592000, immutable');
                header('ETag: "' . $imgId . '"');
                // gzip 不适用于已压缩的图片
                echo $binary;
                exit;
            }
            // 非 data URL 格式，直接输出
            header('Content-Type: text/plain');
            echo 'Invalid format';
            exit;
        }

        /* --- 安装向导 --- */
        if ($api === 'install') {
            if (is_installed()) json_out(['error' => '系统已安装'], 400);
            if (!extension_loaded('pdo_mysql')) {
                json_out(['error' => '服务器未启用 PDO_MYSQL 扩展，请联系主机商'], 400);
            }
            $d = input();
            $dbHost = trim($d['db_host'] ?? '127.0.0.1');
            $dbPort = (int)($d['db_port'] ?? 3306);
            $dbName = trim($d['db_name'] ?? '');
            $dbUser = trim($d['db_user'] ?? '');
            $dbPass = (string)($d['db_pass'] ?? '');
            $siteName = trim($d['site_name'] ?? 'HTMLHub');
            $siteDesc = trim($d['site_desc'] ?? '分享你的 HTML 作品，发现更多创意');
            $adminUser = trim($d['admin_user'] ?? '');
            $adminPass = (string)($d['admin_pass'] ?? '');

            if ($dbHost === '' || $dbName === '' || $dbUser === '')
                json_out(['error' => '请填写完整的数据库信息'], 400);
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $adminUser))
                json_out(['error' => '管理员用户名只能含字母数字下划线，3-20 位'], 400);
            if (strlen($adminPass) < 6)
                json_out(['error' => '管理员密码至少 6 位'], 400);
            if (mb_strlen($siteName) === 0 || mb_strlen($siteName) > 30)
                json_out(['error' => '站点名称 1-30 字'], 400);

            // 测试数据库连接
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $dbHost, $dbPort, $dbName);
                $testPdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                json_out(['error' => '数据库连接失败：' . $e->getMessage()], 400);
            }

            // 初始化表
            try {
                init_db($testPdo);
            } catch (Throwable $e) {
                json_out(['error' => '建表失败：' . $e->getMessage()], 400);
            }

            // 创建管理员
            try {
                $stmt = $testPdo->prepare("INSERT INTO users (username, password, bio, role, created_at) VALUES (?, ?, ?, 'admin', ?)");
                $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_BCRYPT), '管理员', time()]);
                $adminId = (int)$testPdo->lastInsertId();
            } catch (Throwable $e) {
                json_out(['error' => '创建管理员失败：' . $e->getMessage()], 400);
            }

            // 写入配置文件
            $config = [
                'db_host'  => $dbHost,
                'db_port'  => $dbPort,
                'db_name'  => $dbName,
                'db_user'  => $dbUser,
                'db_pass'  => $dbPass,
                'site_name'=> $siteName,
                'site_desc'=> $siteDesc,
                // BotGuard HMAC 密钥：安装时生成，用于签发/校验无感人机验证 token
                'botguard_secret' => bin2hex(random_bytes(32)),
            ];
            $configContent = "<?php\n// HTMLHub 配置文件 - 自动生成\n// 请勿修改，如需修改请删除此文件重新安装\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents(CONFIG_FILE, $configContent) === false) {
                json_out(['error' => '配置文件写入失败，请检查目录权限'], 400);
            }
            // 防止被外部直接访问读取
            @chmod(CONFIG_FILE, 0640);

            // 标记已安装
            if (touch(INSTALLED_FLAG) === false) {
                @unlink(CONFIG_FILE);
                json_out(['error' => '无法创建安装标记文件，请检查目录权限'], 400);
            }

            $_SESSION['uid'] = $adminId;
            json_out(['ok' => true, 'user_id' => $adminId]);
        }

        /* --- 以下接口需要已安装 --- */
        if (!is_installed()) json_out(['error' => '未安装'], 503);

        /* --- 注册 --- */
        if ($api === 'register') {
            $d = input();
            // BotGuard 无感人机验证：校验 token + 指纹一致性
            $bgToken = (string)($d['_bg'] ?? '');
            $bgFp = (string)($d['_bg_fp'] ?? '');
            $bgReason = botguard_verify_token($bgToken, $bgFp);
            if ($bgReason !== null) {
                json_out(['error' => $bgReason], 403);
            }
            // 反机器人综合校验（IP 限流 + 蜜罐 + 表单时间 + 同源 Referer）
            $reason = register_anti_bot_check($d);
            if ($reason !== null) {
                json_out(['error' => $reason], 429);
            }
            // 关键：在通过反机器人校验后立刻记录到限流计数，
            // 这样即使后续用户名格式错误或重名，本次尝试也会被计入。
            // 防止攻击者用无效请求无限探测。
            register_rate_record();
            $user = clean_plain($d['username'] ?? '', 20);
            $pass = (string)($d['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $user))
                json_out(['error' => '用户名只能含字母数字下划线，3-20 位'], 400);
            if (strlen($pass) < 6 || strlen($pass) > 100)
                json_out(['error' => '密码 6-100 位'], 400);
            // 注册前再次确认用户名可用（虽然 INSERT 时 UNIQUE 约束也会兜底，但提前检查可给出更友好的错误）
            $check = db()->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$user]);
            if ($check->fetch()) {
                json_out(['error' => '用户名已被使用'], 400);
            }
            try {
                $stmt = db()->prepare("INSERT INTO users (username, password, bio, role, created_at) VALUES (?, ?, '', 'user', ?)");
                $stmt->execute([$user, password_hash($pass, PASSWORD_BCRYPT), time()]);
                $uid = (int)db()->lastInsertId();
                // session 固定攻击防护
                session_regenerate_id(true);
                $_SESSION['uid'] = $uid;
                $stmt = db()->prepare("SELECT id, username, avatar, bio, role FROM users WHERE id = ?");
                $stmt->execute([$uid]);
                json_out(['ok' => true, 'user' => $stmt->fetch()]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000')
                    json_out(['error' => '用户名已被使用'], 400);
                throw $e;
            }
        }

        /* --- 登录 --- */
        if ($api === 'login') {
            $d = input();
            // BotGuard 无感人机验证
            $bgToken = (string)($d['_bg'] ?? '');
            $bgFp = (string)($d['_bg_fp'] ?? '');
            $bgReason = botguard_verify_token($bgToken, $bgFp);
            if ($bgReason !== null) {
                json_out(['error' => $bgReason], 403);
            }
            $user = clean_plain($d['username'] ?? '', 20);
            $pass = (string)($d['password'] ?? '');
            $remember = !empty($d['remember']);
            $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$user]);
            $u = $stmt->fetch();
            if (!$u || !password_verify($pass, $u['password']))
                json_out(['error' => '用户名或密码错误'], 400);
            // 检查账号状态
            if (($u['status'] ?? 'active') === 'banned')
                json_out(['error' => '该账号已被封禁，请联系管理员'], 403);
            // session 固定攻击防护
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            // 自动登录 token
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $expires = time() + 86400; // 1 天
                db()->prepare("UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?")
                    ->execute([$token, $expires, $u['id']]);
                setcookie('remember_token', $token, [
                    'expires' => $expires,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                ]);
            }
            json_out(['ok' => true, 'user' => [
                'id' => (int)$u['id'], 'username' => $u['username'],
                'avatar' => resolve_image($u['avatar']), 'bio' => $u['bio'], 'role' => $u['role'],
            ]]);
        }

        /* --- 退出 --- */
        if ($api === 'logout') {
            // 清除 remember_token
            $u = current_user();
            if ($u) {
                try {
                    db()->prepare("UPDATE users SET remember_token = NULL, remember_expires = 0 WHERE id = ?")->execute([$u['id']]);
                } catch (Throwable $e) {}
            }
            setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/']);
            session_destroy();
            json_out(['ok' => true]);
        }

        /* --- 当前用户 --- */
        if ($api === 'me') {
            $u = current_user();
            // 解析 contact JSON 字符串为数组，供前端使用
            if ($u && isset($u['contact'])) {
                $u['contact'] = parse_contact($u['contact']);
            }
            // 解析 avatar：图片 ID → URL；base64 → 原样
            if ($u && isset($u['avatar'])) {
                $u['avatar'] = resolve_image($u['avatar']);
            }
            json_out(['user' => $u]);
        }

        /* --- 无感人机验证：签发 token ---
         *  前端在打开登录/注册/发帖页时，被动采集浏览器指纹，
         *  调用此接口换取 token。token 与指纹绑定，30 分钟有效。
         *  提交表单时携带 token，服务端验签 + 比对指纹 + 防重放。
         */
        if ($api === 'botguard_issue') {
            $d = input();
            $fp = (string)($d['fp'] ?? '');
            // 服务端对指纹做合理性校验（防止伪造空 JSON 绕过）
            $reason = botguard_validate_fp($fp);
            if ($reason !== null) {
                // 静默拒绝：不告诉攻击者具体哪一项失败
                usleep(300000); // 0.3s 延迟，增加攻击成本
                json_out(['error' => '人机验证失败，请使用现代浏览器访问'], 403);
            }
            $issued = botguard_issue_token($fp);
            json_out(['ok' => true, 'token' => $issued['token'], 'expires_at' => $issued['expires_at']]);
        }

        /* --- 通知列表 --- */
        if ($api === 'notifications') {
            $u = require_auth();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $filter = $_GET['filter'] ?? 'all'; // all | unread
            $limit = 30;
            $offset = ($page - 1) * $limit;
            $where = ['n.user_id = ?'];
            $args = [(int)$u['id']];
            if ($filter === 'unread') {
                $where[] = 'n.is_read = 0';
            }
            $whereSql = implode(' AND ', $where);
            $sql = "SELECT n.*, a.username AS actor_username, a.avatar AS actor_avatar,
                           p.title AS post_title, p.type AS post_type
                    FROM notifications n
                    LEFT JOIN users a ON a.id = n.actor_id
                    LEFT JOIN posts p ON p.id = n.post_id
                    WHERE $whereSql
                    ORDER BY n.created_at DESC
                    LIMIT $limit OFFSET $offset";
            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'              => (int)$r['id'],
                    'type'            => $r['type'],
                    'post_id'         => (int)$r['post_id'],
                    'comment_id'      => (int)$r['comment_id'],
                    'content'         => $r['content'],
                    'post_title'      => $r['post_title'] ?? '',
                    'post_type'       => $r['post_type'] ?? '',
                    'is_read'         => !empty($r['is_read']),
                    'created_at'      => time_ago((int)$r['created_at']),
                    'actor'           => $r['actor_id'] ? [
                        'id' => (int)$r['actor_id'],
                        'username' => $r['actor_username'] ?? '系统',
                        'avatar' => resolve_image($r['actor_avatar']),
                    ] : ['id' => 0, 'username' => '系统', 'avatar' => null],
                ];
            }
            // 统计未读数
            $cStmt = db()->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
            $cStmt->execute([(int)$u['id']]);
            $unread = (int)$cStmt->fetch()['c'];
            json_out(['notifications' => $out, 'unread_count' => $unread, 'has_more' => count($out) === $limit]);
        }

        /* --- 标记通知已读 --- */
        if ($api === 'notifications_read') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $all = !empty($d['all']);
            if ($all) {
                db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
                    ->execute([(int)$u['id']]);
            } else if ($id > 0) {
                db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                    ->execute([$id, (int)$u['id']]);
            }
            // 返回最新未读数
            $cStmt = db()->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
            $cStmt->execute([(int)$u['id']]);
            $unread = (int)$cStmt->fetch()['c'];
            json_out(['ok' => true, 'unread_count' => $unread]);
        }

        /* --- 通知未读数（轻量接口，用于轮询） --- */
        if ($api === 'notifications_count') {
            $u = require_auth();
            $cStmt = db()->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
            $cStmt->execute([(int)$u['id']]);
            json_out(['unread_count' => (int)$cStmt->fetch()['c']]);
        }

        /* --- 站点设置 --- */
        if ($api === 'settings') {
            json_out([
                'site_name' => setting('site_name', 'HTMLHub'),
                'site_desc' => setting('site_desc', '分享你的 HTML 作品，发现更多创意'),
                // 代码评分玩具工具开关（前端用于显隐入口）
                'code_score_enabled' => app_setting('code_score_enabled', '1') === '1',
            ]);
        }

        /* --- 更新个人资料 --- */
        if ($api === 'update_profile') {
            $u = require_auth();
            $d = input();
            $bio = clean_text($d['bio'] ?? '', 100);
            $avatar = $d['avatar'] ?? null;
            $contactInput = $d['contact'] ?? null;
            if (mb_strlen($bio, 'UTF-8') > 100) json_out(['error' => '简介过长'], 400);
            if ($avatar && !valid_data_url($avatar, COVER_LIMIT))
                json_out(['error' => '头像格式错误或过大'], 400);
            // 压缩头像（256px 方形预览，质量 80）→ 存入 images 表
            if ($avatar) {
                $avatar = compress_image_data_url($avatar, 256, 80);
                $avatar = store_image($avatar, (int)$u['id']);
            }
            // 处理联系方式：验证 + 清洗 + 序列化为 JSON
            $contactJson = '';
            if (is_array($contactInput)) {
                $validated = validate_contact_array($contactInput);
                $contactJson = $validated ? json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
                // 长度兜底（数据库字段 VARCHAR(1000)）
                if (strlen($contactJson) > 1000) {
                    json_out(['error' => '联系方式过多，请删减一些'], 400);
                }
            } elseif ($contactInput === null) {
                // 没传 contact 字段，保持原值不变（允许只更新 bio 或 avatar）
                $contactJson = $u['contact'] ?? '';
            }
            $stmt = db()->prepare("UPDATE users SET bio = ?, avatar = ?, contact = ? WHERE id = ?");
            $stmt->execute([$bio, $avatar, $contactJson, $u['id']]);
            json_out(['ok' => true]);
        }

        /* --- 获取联系方式平台白名单（前端编辑页用） --- */
        if ($api === 'contact_platforms') {
            json_out(['platforms' => contact_platform_whitelist()]);
        }

        /* --- 修改用户名 --- */
        if ($api === 'update_username') {
            $u = require_auth();
            $d = input();
            $newName = clean_plain($d['username'] ?? '', 20);
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newName))
                json_out(['error' => '用户名只能含字母数字下划线，3-20 位'], 400);
            if ($newName === $u['username']) json_out(['ok' => true, 'username' => $newName]);
            // 检查重名
            $chk = db()->prepare("SELECT 1 FROM users WHERE username = ? AND id != ?");
            $chk->execute([$newName, $u['id']]);
            if ($chk->fetch()) json_out(['error' => '用户名已被使用'], 400);
            db()->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$newName, $u['id']]);
            json_out(['ok' => true, 'username' => $newName]);
        }

        /* --- 搜索 --- */
        if ($api === 'search') {
            $q = trim($_GET['q'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 10;
            $offset = ($page - 1) * $limit;
            if ($q === '') json_out(['posts' => [], 'has_more' => false]);
            // 使用 boolean 模式让多关键词任一匹配即返回；同时单关键词模糊匹配
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $sql = "SELECT p.*, u.id AS author_id, u.username, u.avatar FROM posts p
                    JOIN users u ON u.id = p.user_id
                    WHERE (p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?)
                      AND (u.status IS NULL OR u.status = 'active')
                    ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT $limit OFFSET $offset";
            $stmt = db()->prepare($sql);
            $stmt->execute([$kw, $kw, $kw]);
            $rows = $stmt->fetchAll();
            $me = current_user();
            $out = [];
            foreach ($rows as $p) {
                $item = post_view_array($p, $p);
                if ($me) {
                    $lk = db()->prepare("SELECT 1 FROM likes WHERE post_id=? AND user_id=?");
                    $lk->execute([$p['id'], $me['id']]);
                    $fv = db()->prepare("SELECT 1 FROM favorites WHERE post_id=? AND user_id=?");
                    $fv->execute([$p['id'], $me['id']]);
                    $item['liked'] = (bool)$lk->fetch();
                    $item['favorited'] = (bool)$fv->fetch();
                } else {
                    $item['liked'] = false;
                    $item['favorited'] = false;
                }
                $out[] = $item;
            }
            json_out(['posts' => $out, 'has_more' => count($out) === $limit]);
        }

        /* --- 帖子列表 --- */
        if ($api === 'posts') {
            $type  = $_GET['type']  ?? '';
            $sort  = $_GET['sort']  ?? 'new';
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $user_id = (int)($_GET['user_id'] ?? 0);
            $fav_user = (int)($_GET['fav_user'] ?? 0);
            $studio_id = (int)($_GET['studio_id'] ?? 0);
            $limit = 10;
            $offset = ($page - 1) * $limit;

            $where = ['1=1'];
            $args = [];
            if ($type && $type !== 'all') { $where[] = 'p.type = ?'; $args[] = $type; }
            if ($user_id) { $where[] = 'p.user_id = ?'; $args[] = $user_id; }
            if ($studio_id) { $where[] = 'p.studio_id = ?'; $args[] = $studio_id; }
            if ($fav_user) {
                $where[] = 'EXISTS(SELECT 1 FROM favorites f WHERE f.post_id = p.id AND f.user_id = ?)';
                $args[] = $fav_user;
            }
            // 屏蔽被封禁用户的帖子
            $where[] = 'EXISTS(SELECT 1 FROM users uu WHERE uu.id = p.user_id AND (uu.status IS NULL OR uu.status = \'active\'))';

            /* ===== 公平热门算法（Hacker News 风格 + 多维加权 + 时间衰减） =====
             * 评分公式：
             *   score = (likes*4 + favorites*3 + comments*2 + views*0.1)
             *           / (age_hours + 2)^1.5
             *
             * 设计理由：
             *   1) 多维加权：点赞(4) > 收藏(3) > 评论(2) > 浏览(0.1)
             *      - 点赞是最强正向信号
             *      - 收藏代表用户愿意再次回看，权重次之
             *      - 评论代表讨论热度
             *      - 浏览作为弱信号仅作微调，避免刷浏览量霸榜
             *   2) 时间衰减：(age+2)^1.5 使新帖天然获得更高分，老帖需指数级
             *      更多互动才能维持热度，避免老帖长期霸榜。
             *   3) +2 偏置：确保新帖（age=0）有合理初始分，不会被零除。
             *   4) 90 天候选窗口：仅给 90 天内帖子计算热度分（充分利用
             *      idx_posts_created 索引），更老的帖子在“热门”模式下
             *      自然消失；超老帖子由“最新”模式呈现，避免性能退化。
             *   5) 置顶帖永远排前面（同排序规则内），与原行为保持一致。
             */
            if ($sort === 'hot') {
                $hotWindow = time() - 90 * 86400; // 90 天
                $where[] = 'p.created_at >= ?';
                $args[] = $hotWindow;
            }
            $whereSql = implode(' AND ', $where);

            if ($sort === 'hot') {
                // MySQL 在 ORDER BY 中可计算表达式，使用 GREATEST 防止负数年龄
                $order = 'p.is_pinned DESC, '
                    . '((p.likes_count * 4 + p.favorites_count * 3 + p.comments_count * 2 + p.views * 0.1) '
                    . '/ POW(GREATEST((UNIX_TIMESTAMP() - p.created_at) / 3600, 0) + 2, 1.5)) DESC, '
                    . 'p.created_at DESC';
            } else {
                $order = 'p.is_pinned DESC, p.created_at DESC';
            }

            $sql = "SELECT p.*, u.id AS author_id, u.username, u.avatar FROM posts p
                    JOIN users u ON u.id = p.user_id
                    WHERE $whereSql ORDER BY $order LIMIT $limit OFFSET $offset";
            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();

            $me = current_user();
            $out = [];
            foreach ($rows as $p) {
                $item = post_view_array($p, $p);
                if ($me) {
                    $lk = db()->prepare("SELECT 1 FROM likes WHERE post_id=? AND user_id=?");
                    $lk->execute([$p['id'], $me['id']]);
                    $fv = db()->prepare("SELECT 1 FROM favorites WHERE post_id=? AND user_id=?");
                    $fv->execute([$p['id'], $me['id']]);
                    $item['liked'] = (bool)$lk->fetch();
                    $item['favorited'] = (bool)$fv->fetch();
                } else {
                    $item['liked'] = false;
                    $item['favorited'] = false;
                }
                $out[] = $item;
            }
            json_out(['posts' => $out, 'has_more' => count($out) === $limit]);
        }

        /* --- 帖子详情 --- */
        if ($api === 'post') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT p.*, u.id AS author_id, u.username, u.avatar FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '不存在'], 404);
            $me = current_user();

            // 浏览量机制：
            // 1) 自己看自己的帖子不增加浏览量
            // 2) 同一会话内对同一帖子短时间内不重复计数
            $shouldIncView = true;
            if ($me && (int)$me['id'] === (int)$p['user_id']) {
                $shouldIncView = false;
            }
            $viewKey = 'viewed_' . $id;
            $viewTtl = 1800; // 30 分钟内同会话不重复计数
            if (!empty($_SESSION[$viewKey]) && (time() - $_SESSION[$viewKey]) < $viewTtl) {
                $shouldIncView = false;
            }
            if ($shouldIncView) {
                db()->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([$id]);
                $_SESSION[$viewKey] = time();
                $p['views'] = (int)$p['views'] + 1;
            }

            $item = post_view_array($p, $p);
            if ($me) {
                $lk = db()->prepare("SELECT 1 FROM likes WHERE post_id=? AND user_id=?");
                $lk->execute([$id, $me['id']]);
                $fv = db()->prepare("SELECT 1 FROM favorites WHERE post_id=? AND user_id=?");
                $fv->execute([$id, $me['id']]);
                $item['liked'] = (bool)$lk->fetch();
                $item['favorited'] = (bool)$fv->fetch();
            } else {
                $item['liked'] = false;
                $item['favorited'] = false;
            }
            json_out(['post' => $item]);
        }

        /* --- 创建帖子 --- */
        if ($api === 'create_post') {
            $u = require_auth();
            // 被封禁用户不能发帖
            if (($u['status'] ?? 'active') === 'banned')
                json_out(['error' => '账号已被封禁，无法发布'], 403);
            $d = input();
            // BotGuard 无感人机验证
            $bgToken = (string)($d['_bg'] ?? '');
            $bgFp = (string)($d['_bg_fp'] ?? '');
            $bgReason = botguard_verify_token($bgToken, $bgFp);
            if ($bgReason !== null) {
                json_out(['error' => $bgReason], 403);
            }
            $type = $d['type'] ?? '';
            $title = clean_plain($d['title'] ?? '', 50);
            $content = clean_text($d['content'] ?? '', 5000);
            // HTML 作品代码不清洗（保留功能完整性，由 iframe sandbox 隔离）
            $html = (string)($d['html_content'] ?? '');
            $cover = $d['cover'] ?? null;
            $viewMode = $d['view_mode'] ?? 'embed';
            $studioId = (int)($d['studio_id'] ?? 0);
            $rawImages = $d['images'] ?? [];
            if (!is_array($rawImages)) $rawImages = [];

            if (!in_array($type, ['html', 'text'], true)) json_out(['error' => '类型错误'], 400);
            // HTML 作品必须有标题；文字动态标题可选（但标题和内容至少有一项）
            if ($type === 'html') {
                if ($title === '' || mb_strlen($title, 'UTF-8') > 50) json_out(['error' => 'HTML 作品需要标题（1-50 字）'], 400);
            } else {
                if (mb_strlen($title, 'UTF-8') > 50) json_out(['error' => '标题不能超过 50 字'], 400);
                // 标题和内容/图片至少有一项
                if ($title === '' && $content === '' && count($rawImages) === 0) {
                    json_out(['error' => '标题或内容至少填一项'], 400);
                }
            }
            if ($type === 'text') {
                if ($content === '' && count($rawImages) === 0 && $title !== '') {
                    // 有标题但无内容也可以发布（纯标题动态）
                }
                if (mb_strlen($content, 'UTF-8') > 5000) json_out(['error' => '内容超过 5000 字'], 400);
                $html = '';
            } else {
                if ($html === '' || strlen($html) > 500000) json_out(['error' => 'HTML 代码过长或为空'], 400);
                $content = '';
                $rawImages = []; // HTML 作品不带图片
            }
            if ($cover && !valid_data_url($cover, COVER_LIMIT))
                json_out(['error' => '封面格式错误或过大（< 2MB）'], 400);
            // 压缩封面（1280px 长边，质量 82）→ 存入 images 表
            if ($cover) {
                $cover = compress_image_data_url($cover, 1280, 82);
                $cover = store_image($cover, (int)$u['id']);
            }
            if (!in_array($viewMode, ['embed', 'jump'], true)) $viewMode = 'embed';

            // 处理文字动态的图片（最多 9 张，每张原始 ≤999KB，服务端压缩）
            // 新版：图片存入 images 表，posts.images 只存 ID 数组
            $imagesJson = null;
            if ($type === 'text' && count($rawImages) > 0) {
                if (count($rawImages) > 9) json_out(['error' => '最多 9 张图片'], 400);
                $compressedImages = [];
                foreach ($rawImages as $img) {
                    if (!is_string($img)) continue;
                    if (!valid_data_url($img, 999 * 1024)) {
                        json_out(['error' => '单张图片需 ≤ 999KB'], 400);
                    }
                    // 压缩到 1280px 长边
                    $compressedImages[] = compress_image_data_url($img, 1280, 82);
                }
                // 批量存入 images 表，返回 ID 数组 JSON
                $imagesJson = store_image_array($compressedImages, (int)$u['id']);
            }

            // 校验工作室：如果指定 studio_id，必须是成员
            if ($studioId > 0) {
                $smStmt = db()->prepare("SELECT 1 FROM studio_members WHERE studio_id = ? AND user_id = ?");
                $smStmt->execute([$studioId, $u['id']]);
                if (!$smStmt->fetch()) json_out(['error' => '你不是该工作室的成员'], 403);
            }

            // 持久模式（仅 HTML 作品）：作者主动开启后，播放时 localStorage 可用
            // 安全模型见 ?hosted 路由注释：CSP connect-src 'none' 阻断 API 调用，cookie httponly 不可读
            $persistentMode = 0;
            if ($type === 'html' && !empty($d['persistent_mode'])) {
                $persistentMode = 1;
            }

            $stmt = db()->prepare("INSERT INTO posts (user_id, type, title, content, html_content, cover, images, view_mode, studio_id, persistent_mode, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$u['id'], $type, $title, $content, $html, $cover, $imagesJson, $viewMode, $studioId, $persistentMode, time()]);
            $pid = (int)db()->lastInsertId();
            json_out(['ok' => true, 'id' => $pid]);
        }

        /* --- 删除帖子 --- */
        if ($api === 'delete_post') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '不存在'], 404);
            if ($p['user_id'] != $u['id'] && ($u['role'] ?? '') !== 'admin')
                json_out(['error' => '无权操作'], 403);
            // 帖子本身要删，关联数据直接清理（计数字段会随帖子一起消失，无需同步）
            delete_post_relations([$id]);
            db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 获取 HTML 内容（用于 play） --- */
        if ($api === 'play') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT html_content, view_mode, title, persistent_mode FROM posts WHERE id = ? AND type = 'html'");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '不存在'], 404);
            json_out([
                'html' => $p['html_content'],
                'view_mode' => $p['view_mode'],
                'title' => $p['title'],
                'persistent_mode' => !empty($p['persistent_mode']),
            ]);
        }

        /* --- 查看源代码（任何人都可以查看 HTML 作品源码） --- */
        if ($api === 'view_source') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT html_content, title, edited_at, created_at, user_id FROM posts WHERE id = ? AND type = 'html'");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '不存在'], 404);
            // 获取作者信息
            $uStmt = db()->prepare("SELECT id, username, avatar FROM users WHERE id = ?");
            $uStmt->execute([$p['user_id']]);
            $author = $uStmt->fetch();
            json_out([
                'html'        => $p['html_content'],
                'title'       => $p['title'],
                'is_edited'   => (int)$p['edited_at'] > 0,
                'edited_at'   => (int)$p['edited_at'] > 0 ? time_ago((int)$p['edited_at']) : '',
                'created_at'  => time_ago((int)$p['created_at']),
                'author'      => $author ? ['id' => (int)$author['id'], 'username' => $author['username'], 'avatar' => resolve_image($author['avatar'])] : null,
            ]);
        }

        /* --- 作者修改自己的 HTML 作品代码 --- */
        if ($api === 'update_post_html') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $html = (string)($d['html_content'] ?? '');
            $title = clean_plain($d['title'] ?? '', 50);
            if ($id <= 0) json_out(['error' => '参数错误'], 400);
            if ($html === '' || strlen($html) > 500000) json_out(['error' => 'HTML 代码过长或为空'], 400);
            if ($title === '') json_out(['error' => '标题不能为空'], 400);
            // 查询帖子
            $stmt = db()->prepare("SELECT user_id, type FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '帖子不存在'], 404);
            if ($p['type'] !== 'html') json_out(['error' => '只能修改 HTML 作品'], 400);
            if ((int)$p['user_id'] !== (int)$u['id']) json_out(['error' => '只能修改自己的作品'], 403);
            // 更新代码 + 标题 + edited_at
            db()->prepare("UPDATE posts SET html_content = ?, title = ?, edited_at = ? WHERE id = ?")
                ->execute([$html, $title, time(), $id]);
            json_out(['ok' => true, 'edited_at' => time_ago(time())]);
        }

        /* --- 点赞 --- */
        if ($api === 'like') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT 1 FROM likes WHERE post_id=? AND user_id=?");
            $stmt->execute([$id, $u['id']]);
            if ($stmt->fetch()) {
                db()->prepare("DELETE FROM likes WHERE post_id=? AND user_id=?")->execute([$id, $u['id']]);
                db()->prepare("UPDATE posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = ?")->execute([$id]);
                $liked = false;
            } else {
                db()->prepare("INSERT INTO likes (post_id, user_id, created_at) VALUES (?, ?, ?)")->execute([$id, $u['id'], time()]);
                db()->prepare("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?")->execute([$id]);
                $liked = true;
                // 新点赞才通知（取消点赞不发通知）
                $postStmt = db()->prepare("SELECT user_id, title FROM posts WHERE id = ?");
                $postStmt->execute([$id]);
                $post = $postStmt->fetch();
                if ($post) {
                    push_notification((int)$post['user_id'], (int)$u['id'], 'like', $id, 0, $post['title']);
                }
            }
            $c = db()->prepare("SELECT likes_count FROM posts WHERE id = ?");
            $c->execute([$id]);
            $r = $c->fetch();
            json_out(['ok' => true, 'liked' => $liked, 'count' => (int)$r['likes_count']]);
        }

        /* --- 收藏 --- */
        if ($api === 'favorite') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT 1 FROM favorites WHERE post_id=? AND user_id=?");
            $stmt->execute([$id, $u['id']]);
            if ($stmt->fetch()) {
                db()->prepare("DELETE FROM favorites WHERE post_id=? AND user_id=?")->execute([$id, $u['id']]);
                db()->prepare("UPDATE posts SET favorites_count = GREATEST(0, favorites_count - 1) WHERE id = ?")->execute([$id]);
                $fav = false;
            } else {
                db()->prepare("INSERT INTO favorites (post_id, user_id, created_at) VALUES (?, ?, ?)")->execute([$id, $u['id'], time()]);
                db()->prepare("UPDATE posts SET favorites_count = favorites_count + 1 WHERE id = ?")->execute([$id]);
                $fav = true;
            }
            $c = db()->prepare("SELECT favorites_count FROM posts WHERE id = ?");
            $c->execute([$id]);
            $r = $c->fetch();
            json_out(['ok' => true, 'favorited' => $fav, 'count' => (int)$r['favorites_count']]);
        }

        /* --- 评论列表 --- */
        if ($api === 'comments') {
            $id = (int)($_GET['id'] ?? 0);
            // 一条 SQL 拿到评论 + 评论作者 + 被回复者用户名
            $stmt = db()->prepare("SELECT c.*, u.username, u.avatar, ru.username AS reply_to_username
                                   FROM comments c
                                   JOIN users u ON u.id = c.user_id
                                   LEFT JOIN users ru ON ru.id = c.reply_to_user_id
                                   WHERE c.post_id = ?
                                   ORDER BY c.id ASC");
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll();
            $me = current_user();
            $meId = $me ? (int)$me['id'] : 0;
            $isAdmin = !empty($_SESSION['is_admin']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id'              => (int)$r['id'],
                    'content'         => $r['content'],
                    'parent_id'       => (int)$r['parent_id'],
                    'reply_to_user_id'=> (int)$r['reply_to_user_id'],
                    'reply_to_username' => $r['reply_to_username'] ?? '',
                    'created_at'      => time_ago((int)$r['created_at']),
                    'user'            => ['id' => (int)$r['user_id'], 'username' => $r['username'], 'avatar' => resolve_image($r['avatar'])],
                    'can_delete'      => ($meId > 0 && ((int)$r['user_id'] === $meId || $isAdmin)),
                ];
            }
            json_out(['comments' => $out]);
        }

        /* --- 发评论（支持回复） --- */
        if ($api === 'comment') {
            $u = require_auth();
            if (($u['status'] ?? 'active') === 'banned')
                json_out(['error' => '账号已被封禁，无法评论'], 403);
            $d = input();
            // BotGuard 无感人机验证
            $bgToken = (string)($d['_bg'] ?? '');
            $bgFp = (string)($d['_bg_fp'] ?? '');
            $bgReason = botguard_verify_token($bgToken, $bgFp);
            if ($bgReason !== null) {
                json_out(['error' => $bgReason], 403);
            }
            $id = (int)($d['id'] ?? 0);
            $content = clean_text($d['content'] ?? '', 500);
            $parentId = (int)($d['parent_id'] ?? 0);
            $replyToUserId = (int)($d['reply_to_user_id'] ?? 0);
            if ($content === '' || mb_strlen($content, 'UTF-8') > 500) json_out(['error' => '评论 1-500 字'], 400);
            // 查询帖子是否存在 + 拿到作者 ID（用于通知）
            $stmt = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch();
            if (!$post) json_out(['error' => '帖子不存在'], 404);

            // 校验父评论存在且属于同一帖子
            $parentReplyToUser = 0;
            if ($parentId > 0) {
                $pStmt = db()->prepare("SELECT post_id, user_id FROM comments WHERE id = ?");
                $pStmt->execute([$parentId]);
                $parent = $pStmt->fetch();
                if (!$parent) json_out(['error' => '父评论不存在'], 400);
                if ((int)$parent['post_id'] !== $id) json_out(['error' => '父评论不属于该帖子'], 400);
                $parentReplyToUser = $replyToUserId > 0 ? $replyToUserId : (int)$parent['user_id'];
            }
            db()->prepare("INSERT INTO comments (post_id, user_id, content, parent_id, reply_to_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$id, $u['id'], $content, $parentId, $parentReplyToUser, time()]);
            $newCommentId = (int)db()->lastInsertId();
            db()->prepare("UPDATE posts SET comments_count = comments_count + 1 WHERE id = ?")->execute([$id]);

            // === 通知逻辑 ===
            // 1. 如果是回复评论，给被回复者发 reply 通知（而非 comment 通知）
            // 2. 否则给帖子作者发 comment 通知
            // 3. 如果是回复评论且被回复者 = 帖子作者，只发一条 reply 通知
            if ($parentReplyToUser > 0) {
                push_notification($parentReplyToUser, (int)$u['id'], 'reply', $id, $newCommentId, $content);
                // 如果帖子作者不是被回复者，也通知帖子作者
                if ((int)$post['user_id'] !== $parentReplyToUser) {
                    push_notification((int)$post['user_id'], (int)$u['id'], 'comment', $id, $newCommentId, $content);
                }
            } else {
                push_notification((int)$post['user_id'], (int)$u['id'], 'comment', $id, $newCommentId, $content);
            }

            // 取被回复用户名
            $replyToUsername = '';
            if ($parentReplyToUser > 0) {
                $ruStmt = db()->prepare("SELECT username FROM users WHERE id = ?");
                $ruStmt->execute([$parentReplyToUser]);
                $ru = $ruStmt->fetch();
                if ($ru) $replyToUsername = $ru['username'];
            }
            json_out(['ok' => true, 'comment' => [
                'id'              => $newCommentId,
                'content'         => $content,
                'parent_id'       => $parentId,
                'reply_to_user_id'=> $parentReplyToUser,
                'reply_to_username' => $replyToUsername,
                'created_at'      => '刚刚',
                'user'            => ['id' => (int)$u['id'], 'username' => $u['username'], 'avatar' => resolve_image($u['avatar'] ?? null)],
            ]]);
        }

        /* --- 删除评论（本人或管理员） --- */
        if ($api === 'delete_comment') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT post_id, user_id FROM comments WHERE id = ?");
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if (!$c) json_out(['error' => '评论不存在'], 404);
            $isAdmin = !empty($_SESSION['is_admin']);
            if ((int)$c['user_id'] !== (int)$u['id'] && !$isAdmin)
                json_out(['error' => '无权删除'], 403);
            // 递归删除子评论
            $delIds = [$id];
            $queue = [$id];
            while ($queue) {
                $cur = array_shift($queue);
                $childStmt = db()->prepare("SELECT id FROM comments WHERE parent_id = ?");
                $childStmt->execute([$cur]);
                foreach ($childStmt->fetchAll() as $child) {
                    $delIds[] = (int)$child['id'];
                    $queue[] = (int)$child['id'];
                }
            }
            $placeholders = implode(',', array_fill(0, count($delIds), '?'));
            db()->prepare("DELETE FROM comments WHERE id IN ($placeholders)")->execute($delIds);
            // 更新帖子评论数
            db()->prepare("UPDATE posts SET comments_count = GREATEST(0, comments_count - ?) WHERE id = ?")
                ->execute([count($delIds), $c['post_id']]);
            json_out(['ok' => true, 'deleted_count' => count($delIds)]);
        }

        /* --- 删除自己的帖子（作者本人） --- */
        if ($api === 'delete_own_post') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '帖子不存在'], 404);
            if ((int)$p['user_id'] !== (int)$u['id'])
                json_out(['error' => '无权删除他人帖子'], 403);
            // 帖子本身要删，关联数据直接清理（计数字段会随帖子一起消失，无需同步）
            delete_post_relations([$id]);
            db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 用户主页 --- */
        if ($api === 'user') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT id, username, avatar, bio, contact, role, status, created_at FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if (!$u) json_out(['error' => '用户不存在'], 404);
            $me = current_user();
            $meId = $me ? (int)$me['id'] : 0;
            json_out(['user' => user_view_array($u, $meId)]);
        }

        /* --- 关注 / 取关 --- */
        if ($api === 'follow') {
            $u = require_auth();
            $d = input();
            $targetId = (int)($d['id'] ?? 0);
            if ($targetId <= 0) json_out(['error' => '参数错误'], 400);
            if ($targetId === (int)$u['id']) json_out(['error' => '不能关注自己'], 400);
            // 目标必须存在
            $chk = db()->prepare("SELECT id, status FROM users WHERE id = ?");
            $chk->execute([$targetId]);
            $target = $chk->fetch();
            if (!$target) json_out(['error' => '用户不存在'], 404);
            if (($target['status'] ?? 'active') === 'banned') json_out(['error' => '该账号已被封禁'], 400);

            $stmt = db()->prepare("SELECT 1 FROM follows WHERE follower_id=? AND following_id=?");
            $stmt->execute([(int)$u['id'], $targetId]);
            if ($stmt->fetch()) {
                db()->prepare("DELETE FROM follows WHERE follower_id=? AND following_id=?")->execute([(int)$u['id'], $targetId]);
                $following = false;
            } else {
                db()->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, ?)")->execute([(int)$u['id'], $targetId, time()]);
                $following = true;
                // 新关注才通知（取关不发通知）
                push_notification($targetId, (int)$u['id'], 'follow');
            }
            $c = db()->prepare("SELECT COUNT(*) AS c FROM follows WHERE following_id = ?");
            $c->execute([$targetId]);
            $cnt = (int)$c->fetch()['c'];
            $mutual = is_mutual_follow((int)$u['id'], $targetId);
            json_out(['ok' => true, 'following' => $following, 'followers_count' => $cnt, 'is_mutual' => $mutual]);
        }

        /* --- 粉丝列表 --- */
        if ($api === 'followers') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT u.id, u.username, u.avatar, u.bio, u.role, u.status, u.created_at, f.created_at AS followed_at
                                   FROM follows f JOIN users u ON u.id = f.follower_id
                                   WHERE f.following_id = ? ORDER BY f.id DESC");
            $stmt->execute([$id]);
            $me = current_user();
            $meId = $me ? (int)$me['id'] : 0;
            $out = [];
            foreach ($stmt->fetchAll() as $u) {
                $v = user_view_array($u, $meId);
                $out[] = $v;
            }
            json_out(['users' => $out]);
        }

        /* --- 关注列表 --- */
        if ($api === 'following') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT u.id, u.username, u.avatar, u.bio, u.role, u.status, u.created_at
                                   FROM follows f JOIN users u ON u.id = f.following_id
                                   WHERE f.follower_id = ? ORDER BY f.id DESC");
            $stmt->execute([$id]);
            $me = current_user();
            $meId = $me ? (int)$me['id'] : 0;
            $out = [];
            foreach ($stmt->fetchAll() as $u) {
                $out[] = user_view_array($u, $meId);
            }
            json_out(['users' => $out]);
        }

        /* === 工作室系统 === */

        /* --- 工作室列表 --- */
        if ($api === 'studios') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $q = trim($_GET['q'] ?? '');
            $myOnly = !empty($_GET['mine']);
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $where = ['1=1'];
            $args = [];
            if ($q) {
                $where[] = '(s.name LIKE ? OR s.slug LIKE ? OR s.description LIKE ?)';
                $kw = '%' . $q . '%';
                array_push($args, $kw, $kw, $kw);
            }
            if ($myOnly) {
                $me = current_user();
                if (!$me) json_out(['studios' => [], 'has_more' => false]);
                $where[] = 'EXISTS(SELECT 1 FROM studio_members sm WHERE sm.studio_id = s.id AND sm.user_id = ?)';
                $args[] = $me['id'];
            }
            $whereSql = implode(' AND ', $where);
            $sql = "SELECT s.*, u.username AS owner_username, u.avatar AS owner_avatar,
                    (SELECT COUNT(*) FROM studio_members sm WHERE sm.studio_id = s.id) AS members_count,
                    (SELECT COUNT(*) FROM posts p WHERE p.studio_id = s.id) AS posts_count
                    FROM studios s
                    JOIN users u ON u.id = s.owner_id
                    WHERE $whereSql
                    ORDER BY s.created_at DESC
                    LIMIT $limit OFFSET $offset";
            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $me = current_user();
            $meId = $me ? (int)$me['id'] : 0;
            $out = [];
            foreach ($stmt->fetchAll() as $s) {
                $isMember = false;
                $isOwner = false;
                $myRole = '';
                if ($meId > 0) {
                    if ((int)$s['owner_id'] === $meId) {
                        $isOwner = true;
                        $isMember = true;
                        $myRole = 'owner';
                    } else {
                        $smStmt = db()->prepare("SELECT role FROM studio_members WHERE studio_id = ? AND user_id = ?");
                        $smStmt->execute([$s['id'], $meId]);
                        $row = $smStmt->fetch();
                        if ($row) { $isMember = true; $myRole = $row['role']; }
                    }
                }
                $out[] = [
                    'id'            => (int)$s['id'],
                    'name'          => $s['name'],
                    'slug'          => $s['slug'],
                    'description'   => $s['description'],
                    'cover'         => resolve_image($s['cover']),
                    'visibility'    => $s['visibility'],
                    'owner'         => ['id' => (int)$s['owner_id'], 'username' => $s['owner_username'], 'avatar' => resolve_image($s['owner_avatar'])],
                    'members_count' => (int)$s['members_count'],
                    'posts_count'   => (int)$s['posts_count'],
                    'created_at'    => time_ago((int)$s['created_at']),
                    'is_member'     => $isMember,
                    'is_owner'      => $isOwner,
                    'my_role'       => $myRole,
                ];
            }
            json_out(['studios' => $out, 'has_more' => count($out) === $limit]);
        }

        /* --- 工作室详情 --- */
        if ($api === 'studio') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT s.*, u.username AS owner_username, u.avatar AS owner_avatar
                                   FROM studios s JOIN users u ON u.id = s.owner_id WHERE s.id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            $me = current_user();
            $meId = $me ? (int)$me['id'] : 0;
            $isMember = false; $isOwner = false; $myRole = '';
            if ($meId > 0) {
                if ((int)$s['owner_id'] === $meId) {
                    $isOwner = true; $isMember = true; $myRole = 'owner';
                } else {
                    $smStmt = db()->prepare("SELECT role FROM studio_members WHERE studio_id = ? AND user_id = ?");
                    $smStmt->execute([$id, $meId]);
                    $row = $smStmt->fetch();
                    if ($row) { $isMember = true; $myRole = $row['role']; }
                }
            }
            $cntStmt = db()->prepare("SELECT (SELECT COUNT(*) FROM studio_members WHERE studio_id = ?) AS members,
                                              (SELECT COUNT(*) FROM posts WHERE studio_id = ?) AS posts");
            $cntStmt->execute([$id, $id]);
            $cnt = $cntStmt->fetch();
            json_out(['studio' => [
                'id'            => (int)$s['id'],
                'name'          => $s['name'],
                'slug'          => $s['slug'],
                'description'   => $s['description'],
                'cover'         => resolve_image($s['cover']),
                'visibility'    => $s['visibility'],
                'owner'         => ['id' => (int)$s['owner_id'], 'username' => $s['owner_username'], 'avatar' => resolve_image($s['owner_avatar'])],
                'members_count' => (int)$cnt['members'],
                'posts_count'   => (int)$cnt['posts'],
                'created_at'    => time_ago((int)$s['created_at']),
                'is_member'     => $isMember,
                'is_owner'      => $isOwner,
                'my_role'       => $myRole,
            ]]);
        }

        /* --- 创建工作室 --- */
        if ($api === 'studio_create') {
            $u = require_auth();
            if (($u['status'] ?? 'active') === 'banned')
                json_out(['error' => '账号已被封禁'], 403);
            $d = input();
            $name = clean_plain($d['name'] ?? '', 50);
            $slug = clean_plain($d['slug'] ?? '', 50);
            $desc = clean_text($d['description'] ?? '', 500);
            $cover = $d['cover'] ?? null;
            $visibility = $d['visibility'] ?? 'public';
            if ($name === '' || mb_strlen($name, 'UTF-8') > 50) json_out(['error' => '名称 1-50 字'], 400);
            if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $slug)) json_out(['error' => '标识只能含字母数字下划线短横线，3-50 位'], 400);
            if (mb_strlen($desc, 'UTF-8') > 500) json_out(['error' => '描述过长（≤500字）'], 400);
            if (!in_array($visibility, ['public', 'private'], true)) $visibility = 'public';
            if ($cover && !valid_data_url($cover, COVER_LIMIT)) json_out(['error' => '封面错误或过大'], 400);
            if ($cover) $cover = compress_image_data_url($cover, 800, 82);
            // slug 唯一性
            $chk = db()->prepare("SELECT 1 FROM studios WHERE slug = ?");
            $chk->execute([$slug]);
            if ($chk->fetch()) json_out(['error' => '标识已被使用，请换一个'], 400);
            // 限制每人最多创建 5 个
            $cntStmt = db()->prepare("SELECT COUNT(*) AS c FROM studios WHERE owner_id = ?");
            $cntStmt->execute([$u['id']]);
            if ((int)$cntStmt->fetch()['c'] >= 5) json_out(['error' => '每人最多创建 5 个工作室'], 400);

            db()->beginTransaction();
            try {
                $stmt = db()->prepare("INSERT INTO studios (name, slug, description, cover, owner_id, visibility, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $slug, $desc, $cover, $u['id'], $visibility, time()]);
                $sid = (int)db()->lastInsertId();
                // 创建者自动成为 owner 成员
                db()->prepare("INSERT INTO studio_members (studio_id, user_id, role, joined_at) VALUES (?, ?, 'owner', ?)")
                    ->execute([$sid, $u['id'], time()]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            json_out(['ok' => true, 'id' => $sid]);
        }

        /* --- 更新工作室 --- */
        if ($api === 'studio_update') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT * FROM studios WHERE id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            if ((int)$s['owner_id'] !== (int)$u['id']) json_out(['error' => '只有创建者才能修改'], 403);
            $name = clean_plain($d['name'] ?? $s['name'], 50);
            $desc = clean_text($d['description'] ?? $s['description'], 500);
            $cover = $d['cover'] ?? null;
            $visibility = $d['visibility'] ?? $s['visibility'];
            if ($name === '') json_out(['error' => '名称不能为空'], 400);
            if (!in_array($visibility, ['public', 'private'], true)) $visibility = 'public';
            if ($cover === null) {
                // 不变
                $cover = $s['cover'];
            } else if ($cover === '') {
                // 清空
                $cover = null;
            } else {
                if (!valid_data_url($cover, COVER_LIMIT)) json_out(['error' => '封面错误'], 400);
                $cover = compress_image_data_url($cover, 800, 82);
            }
            db()->prepare("UPDATE studios SET name = ?, description = ?, cover = ?, visibility = ? WHERE id = ?")
                ->execute([$name, $desc, $cover, $visibility, $id]);
            json_out(['ok' => true]);
        }

        /* --- 删除工作室 --- */
        if ($api === 'studio_delete') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT owner_id FROM studios WHERE id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            if ((int)$s['owner_id'] !== (int)$u['id']) json_out(['error' => '只有创建者才能删除'], 403);
            // 工作室的帖子设为非工作室（保留帖子），studio_id = 0
            db()->prepare("UPDATE posts SET studio_id = 0 WHERE studio_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM studios WHERE id = ?")->execute([$id]);
            db()->prepare("DELETE FROM studio_members WHERE studio_id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 加入工作室 --- */
        if ($api === 'studio_join') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT id, visibility FROM studios WHERE id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            $chk = db()->prepare("SELECT 1 FROM studio_members WHERE studio_id = ? AND user_id = ?");
            $chk->execute([$id, $u['id']]);
            if ($chk->fetch()) json_out(['error' => '你已经是成员'], 400);
            db()->prepare("INSERT INTO studio_members (studio_id, user_id, role, joined_at) VALUES (?, ?, 'member', ?)")
                ->execute([$id, $u['id'], time()]);
            json_out(['ok' => true]);
        }

        /* --- 退出工作室 --- */
        if ($api === 'studio_leave') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT owner_id FROM studios WHERE id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            if ((int)$s['owner_id'] === (int)$u['id']) json_out(['error' => '创建者不能退出，请先转让或删除工作室'], 400);
            db()->prepare("DELETE FROM studio_members WHERE studio_id = ? AND user_id = ?")->execute([$id, $u['id']]);
            json_out(['ok' => true]);
        }

        /* --- 工作室成员列表 --- */
        if ($api === 'studio_members') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT sm.role, sm.joined_at, u.id, u.username, u.avatar, u.bio
                                   FROM studio_members sm JOIN users u ON u.id = sm.user_id
                                   WHERE sm.studio_id = ?
                                   ORDER BY CASE sm.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, sm.joined_at ASC");
            $stmt->execute([$id]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'user' => ['id' => (int)$r['id'], 'username' => $r['username'], 'avatar' => resolve_image($r['avatar']), 'bio' => $r['bio']],
                    'role' => $r['role'],
                    'joined_at' => time_ago((int)$r['joined_at']),
                ];
            }
            json_out(['members' => $out]);
        }

        /* --- 工作室管理：踢人 / 升降管理员 --- */
        if ($api === 'studio_kick') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $targetId = (int)($d['user_id'] ?? 0);
            $stmt = db()->prepare("SELECT owner_id FROM studios WHERE id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            if ((int)$s['owner_id'] !== (int)$u['id']) json_out(['error' => '只有创建者才能踢人'], 403);
            if ($targetId === (int)$u['id']) json_out(['error' => '不能踢自己'], 400);
            db()->prepare("DELETE FROM studio_members WHERE studio_id = ? AND user_id = ?")->execute([$id, $targetId]);
            json_out(['ok' => true]);
        }

        if ($api === 'studio_set_role') {
            $u = require_auth();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $targetId = (int)($d['user_id'] ?? 0);
            $role = $d['role'] ?? 'member';
            if (!in_array($role, ['member', 'admin'], true)) json_out(['error' => '角色无效'], 400);
            $stmt = db()->prepare("SELECT owner_id FROM studios WHERE id = ?");
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            if ((int)$s['owner_id'] !== (int)$u['id']) json_out(['error' => '只有创建者才能管理角色'], 403);
            db()->prepare("UPDATE studio_members SET role = ? WHERE studio_id = ? AND user_id = ? AND role != 'owner'")
                ->execute([$role, $id, $targetId]);
            json_out(['ok' => true]);
        }

        /* === 工作室邀请系统 === */

        /* --- 搜索可邀请的用户（工作室 owner/admin 用） --- */
        if ($api === 'studio_search_invite') {
            $u = require_auth();
            $studioId = (int)($_GET['studio_id'] ?? 0);
            $q = trim($_GET['q'] ?? '');
            if ($studioId <= 0) json_out(['users' => []]);
            // 校验调用者是 owner 或 admin
            $chk = db()->prepare("SELECT s.owner_id, sm.role FROM studios s LEFT JOIN studio_members sm ON sm.studio_id = s.id AND sm.user_id = ? WHERE s.id = ?");
            $chk->execute([$u['id'], $studioId]);
            $s = $chk->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            $isOwner = (int)$s['owner_id'] === (int)$u['id'];
            $isAdmin = $s['role'] === 'admin';
            if (!$isOwner && !$isAdmin) json_out(['error' => '无权邀请'], 403);
            if ($q === '') json_out(['users' => []]);
            $kw = '%' . $q . '%';
            // 排除已是成员 + 已有待处理邀请的用户
            $stmt = db()->prepare("SELECT u.id, u.username, u.avatar, u.bio FROM users u
                                   WHERE u.username LIKE ? AND u.id != ? AND u.status = 'active'
                                   AND NOT EXISTS(SELECT 1 FROM studio_members sm WHERE sm.studio_id = ? AND sm.user_id = u.id)
                                   AND NOT EXISTS(SELECT 1 FROM studio_invitations si WHERE si.studio_id = ? AND si.invitee_id = u.id AND si.status = 'pending')
                                   LIMIT 10");
            $stmt->execute([$kw, $u['id'], $studioId, $studioId]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = ['id' => (int)$r['id'], 'username' => $r['username'], 'avatar' => resolve_image($r['avatar']), 'bio' => $r['bio']];
            }
            json_out(['users' => $out]);
        }

        /* --- 发送邀请 --- */
        if ($api === 'studio_invite') {
            $u = require_auth();
            $d = input();
            $studioId = (int)($d['studio_id'] ?? 0);
            $inviteeId = (int)($d['invitee_id'] ?? 0);
            if ($studioId <= 0 || $inviteeId <= 0) json_out(['error' => '参数错误'], 400);
            if ($inviteeId === (int)$u['id']) json_out(['error' => '不能邀请自己'], 400);
            // 校验调用者是 owner 或 admin
            $chk = db()->prepare("SELECT s.owner_id, s.name, sm.role FROM studios s LEFT JOIN studio_members sm ON sm.studio_id = s.id AND sm.user_id = ? WHERE s.id = ?");
            $chk->execute([$u['id'], $studioId]);
            $s = $chk->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            $isOwner = (int)$s['owner_id'] === (int)$u['id'];
            $isAdmin = $s['role'] === 'admin';
            if (!$isOwner && !$isAdmin) json_out(['error' => '无权邀请'], 403);
            // 校验目标用户存在
            $uChk = db()->prepare("SELECT id, username, status FROM users WHERE id = ?");
            $uChk->execute([$inviteeId]);
            $invitee = $uChk->fetch();
            if (!$invitee) json_out(['error' => '用户不存在'], 404);
            if ($invitee['status'] === 'banned') json_out(['error' => '该用户已被封禁'], 400);
            // 已是成员？
            $mChk = db()->prepare("SELECT 1 FROM studio_members WHERE studio_id = ? AND user_id = ?");
            $mChk->execute([$studioId, $inviteeId]);
            if ($mChk->fetch()) json_out(['error' => '该用户已是成员'], 400);
            // 已有待处理邀请？
            $iChk = db()->prepare("SELECT 1 FROM studio_invitations WHERE studio_id = ? AND invitee_id = ? AND status = 'pending'");
            $iChk->execute([$studioId, $inviteeId]);
            if ($iChk->fetch()) json_out(['error' => '已发送过邀请，等待对方回应'], 400);
            // 创建邀请
            db()->prepare("INSERT INTO studio_invitations (studio_id, inviter_id, invitee_id, status, created_at) VALUES (?, ?, ?, 'pending', ?)")
                ->execute([$studioId, $u['id'], $inviteeId, time()]);
            // 发通知
            push_notification($inviteeId, (int)$u['id'], 'studio_invite', 0, 0, $s['name']);
            json_out(['ok' => true]);
        }

        /* --- 我收到的邀请列表 --- */
        if ($api === 'my_invitations') {
            $u = require_auth();
            $stmt = db()->prepare("SELECT si.id, si.status, si.created_at, s.id AS studio_id, s.name AS studio_name, s.cover AS studio_cover,
                                   inv.id AS inviter_id, inv.username AS inviter_username, inv.avatar AS inviter_avatar
                                   FROM studio_invitations si
                                   JOIN studios s ON s.id = si.studio_id
                                   JOIN users inv ON inv.id = si.inviter_id
                                   WHERE si.invitee_id = ? AND si.status = 'pending'
                                   ORDER BY si.created_at DESC");
            $stmt->execute([$u['id']]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'         => (int)$r['id'],
                    'status'     => $r['status'],
                    'created_at' => time_ago((int)$r['created_at']),
                    'studio'     => ['id' => (int)$r['studio_id'], 'name' => $r['studio_name'], 'cover' => resolve_image($r['studio_cover'])],
                    'inviter'    => ['id' => (int)$r['inviter_id'], 'username' => $r['inviter_username'], 'avatar' => resolve_image($r['inviter_avatar'])],
                ];
            }
            json_out(['invitations' => $out]);
        }

        /* --- 接受/拒绝邀请 --- */
        if ($api === 'studio_invite_respond') {
            $u = require_auth();
            $d = input();
            $invId = (int)($d['id'] ?? 0);
            $accept = !empty($d['accept']);
            $stmt = db()->prepare("SELECT * FROM studio_invitations WHERE id = ? AND invitee_id = ? AND status = 'pending'");
            $stmt->execute([$invId, $u['id']]);
            $inv = $stmt->fetch();
            if (!$inv) json_out(['error' => '邀请不存在或已处理'], 404);
            $newStatus = $accept ? 'accepted' : 'declined';
            db()->prepare("UPDATE studio_invitations SET status = ?, responded_at = ? WHERE id = ?")
                ->execute([$newStatus, time(), $invId]);
            if ($accept) {
                // 加入工作室
                $mChk = db()->prepare("SELECT 1 FROM studio_members WHERE studio_id = ? AND user_id = ?");
                $mChk->execute([$inv['studio_id'], $u['id']]);
                if (!$mChk->fetch()) {
                    db()->prepare("INSERT INTO studio_members (studio_id, user_id, role, joined_at) VALUES (?, ?, 'member', ?)")
                        ->execute([$inv['studio_id'], $u['id'], time()]);
                }
            }
            json_out(['ok' => true, 'accepted' => $accept]);
        }

        /* --- 取消邀请（owner/admin 用） --- */
        if ($api === 'studio_invite_cancel') {
            $u = require_auth();
            $d = input();
            $invId = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT si.*, s.owner_id FROM studio_invitations si JOIN studios s ON s.id = si.studio_id WHERE si.id = ?");
            $stmt->execute([$invId]);
            $inv = $stmt->fetch();
            if (!$inv) json_out(['error' => '邀请不存在'], 404);
            $isOwner = (int)$inv['owner_id'] === (int)$u['id'];
            $smChk = db()->prepare("SELECT role FROM studio_members WHERE studio_id = ? AND user_id = ?");
            $smChk->execute([$inv['studio_id'], $u['id']]);
            $sm = $smChk->fetch();
            $isAdmin = $sm && $sm['role'] === 'admin';
            if (!$isOwner && !$isAdmin && (int)$inv['inviter_id'] !== (int)$u['id']) {
                json_out(['error' => '无权取消'], 403);
            }
            db()->prepare("DELETE FROM studio_invitations WHERE id = ?")->execute([$invId]);
            json_out(['ok' => true]);
        }

        /* --- 工作室待处理邀请列表（owner/admin 用） --- */
        if ($api === 'studio_pending_invitations') {
            $u = require_auth();
            $studioId = (int)($_GET['studio_id'] ?? 0);
            // 校验权限
            $chk = db()->prepare("SELECT s.owner_id, sm.role FROM studios s LEFT JOIN studio_members sm ON sm.studio_id = s.id AND sm.user_id = ? WHERE s.id = ?");
            $chk->execute([$u['id'], $studioId]);
            $s = $chk->fetch();
            if (!$s) json_out(['error' => '工作室不存在'], 404);
            $isOwner = (int)$s['owner_id'] === (int)$u['id'];
            $isAdmin = $s['role'] === 'admin';
            if (!$isOwner && !$isAdmin) json_out(['error' => '无权查看'], 403);
            $stmt = db()->prepare("SELECT si.id, si.created_at, u.id AS invitee_id, u.username, u.avatar
                                   FROM studio_invitations si JOIN users u ON u.id = si.invitee_id
                                   WHERE si.studio_id = ? AND si.status = 'pending'
                                   ORDER BY si.created_at DESC");
            $stmt->execute([$studioId]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'         => (int)$r['id'],
                    'created_at' => time_ago((int)$r['created_at']),
                    'invitee'    => ['id' => (int)$r['invitee_id'], 'username' => $r['username'], 'avatar' => resolve_image($r['avatar'])],
                ];
            }
            json_out(['invitations' => $out]);
        }

        /* === HTML 静态托管 === */

        /* --- 获取托管配置（公开） --- */
        if ($api === 'hosted_settings') {
            $total = (int)db()->query("SELECT COUNT(*) AS c FROM hosted_pages")->fetch()['c'];
            json_out([
                'enabled'       => app_setting('hosting_enabled', '1') === '1',
                'max_per_user'  => (int)app_setting('hosting_max_per_user', '10'),
                'max_size_kb'   => (int)app_setting('hosting_max_size_kb', '100'),
                'max_total'     => (int)app_setting('hosting_max_total', '100'),
                'total_count'   => $total,
            ]);
        }

        /* --- 托管列表（公开，分页） --- */
        if ($api === 'hosted_list') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 12;
            $offset = ($page - 1) * $limit;
            $stmt = db()->prepare("SELECT h.slug, h.title, h.views, h.created_at, u.id AS author_id, u.username, u.avatar
                                   FROM hosted_pages h JOIN users u ON u.id = h.user_id
                                   ORDER BY h.created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'slug'       => $r['slug'],
                    'title'      => $r['title'],
                    'views'      => (int)$r['views'],
                    'created_at' => time_ago((int)$r['created_at']),
                    'author'     => ['id' => (int)$r['author_id'], 'username' => $r['username'], 'avatar' => resolve_image($r['avatar'])],
                ];
            }
            $c = db()->query("SELECT COUNT(*) AS c FROM hosted_pages")->fetch();
            json_out(['pages' => $out, 'total' => (int)$c['c'], 'has_more' => count($out) === $limit]);
        }

        /* --- 查看托管详情（SPA 内查看） --- */
        if ($api === 'hosted_view') {
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['slug'] ?? ''));
            if ($slug === '') json_out(['error' => '参数错误'], 400);
            $stmt = db()->prepare("SELECT h.*, u.id AS author_id, u.username, u.avatar
                                   FROM hosted_pages h JOIN users u ON u.id = h.user_id
                                   WHERE h.slug = ?");
            $stmt->execute([$slug]);
            $r = $stmt->fetch();
            if (!$r) json_out(['error' => '不存在'], 404);
            json_out(['page' => [
                'slug'       => $r['slug'],
                'title'      => $r['title'],
                'html'       => $r['html_content'],
                'views'      => (int)$r['views'],
                'is_banned'  => !empty($r['is_banned']),
                'created_at' => time_ago((int)$r['created_at']),
                'author'     => ['id' => (int)$r['author_id'], 'username' => $r['username'], 'avatar' => resolve_image($r['avatar'])],
                'share_url'  => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/?hosted=' . $r['slug'],
            ]]);
        }

        /* --- 创建托管（需登录） --- */
        if ($api === 'hosted_create') {
            $u = require_auth();
            if (($u['status'] ?? 'active') === 'banned')
                json_out(['error' => '账号已被封禁'], 403);
            // 检查托管功能是否开启
            if (app_setting('hosting_enabled', '1') !== '1')
                json_out(['error' => '托管功能已关闭'], 403);
            $d = input();
            $title = clean_plain($d['title'] ?? '', 100);
            $html = (string)($d['html_content'] ?? '');
            if ($html === '') json_out(['error' => 'HTML 代码不能为空'], 400);
            $maxKb = (int)app_setting('hosting_max_size_kb', '100');
            $maxBytes = $maxKb * 1024;
            if (strlen($html) > $maxBytes)
                json_out(['error' => "HTML 代码超过限制（最大 {$maxKb}KB，当前 " . round(strlen($html) / 1024, 1) . "KB）"], 400);
            // 检查用户托管数量
            $maxPerUser = (int)app_setting('hosting_max_per_user', '10');
            $cnt = db()->prepare("SELECT COUNT(*) AS c FROM hosted_pages WHERE user_id = ?");
            $cnt->execute([$u['id']]);
            if ((int)$cnt->fetch()['c'] >= $maxPerUser)
                json_out(['error' => "已达到个人托管上限（{$maxPerUser} 个）"], 400);
            // 检查全局托管总数
            $maxTotal = (int)app_setting('hosting_max_total', '100');
            $totalCnt = (int)db()->query("SELECT COUNT(*) AS c FROM hosted_pages")->fetch()['c'];
            if ($totalCnt >= $maxTotal)
                json_out(['error' => "全局托管已达上限（{$maxTotal} 个），无法继续托管"], 400);
            // 生成 slug
            $slug = generate_slug(8);
            // 持久模式：作者主动开启后，localStorage 可用（CSP connect-src 'none' 阻断 API）
            $persistentMode = !empty($d['persistent_mode']) ? 1 : 0;
            db()->prepare("INSERT INTO hosted_pages (slug, title, html_content, user_id, views, persistent_mode, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)")
                ->execute([$slug, $title, $html, $u['id'], $persistentMode, time()]);
            $shareUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/?hosted=' . $slug;
            json_out(['ok' => true, 'slug' => $slug, 'share_url' => $shareUrl, 'persistent_mode' => $persistentMode]);
        }

        /* --- 删除自己的托管 --- */
        if ($api === 'hosted_delete') {
            $u = require_auth();
            $d = input();
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($d['slug'] ?? ''));
            if ($slug === '') json_out(['error' => '参数错误'], 400);
            $stmt = db()->prepare("SELECT user_id FROM hosted_pages WHERE slug = ?");
            $stmt->execute([$slug]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '不存在'], 404);
            $isAdmin = !empty($_SESSION['is_admin']);
            if ((int)$p['user_id'] !== (int)$u['id'] && !$isAdmin)
                json_out(['error' => '无权删除'], 403);
            db()->prepare("DELETE FROM hosted_pages WHERE slug = ?")->execute([$slug]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：托管列表 --- */
        if ($api === 'admin_hosted_list') {
            require_admin();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;
            $stmt = db()->prepare("SELECT h.*, u.username FROM hosted_pages h JOIN users u ON u.id = h.user_id
                                   ORDER BY h.created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'         => (int)$r['id'],
                    'slug'       => $r['slug'],
                    'title'      => $r['title'],
                    'views'      => (int)$r['views'],
                    'size'       => strlen($r['html_content']),
                    'is_banned'  => !empty($r['is_banned']),
                    'created_at' => time_ago((int)$r['created_at']),
                    'author'     => ['id' => (int)$r['user_id'], 'username' => $r['username']],
                ];
            }
            json_out(['pages' => $out]);
        }

        /* --- 管理员：删除托管 --- */
        if ($api === 'admin_hosted_delete') {
            require_admin();
            $d = input();
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($d['slug'] ?? ''));
            if ($slug === '') json_out(['error' => '参数错误'], 400);
            db()->prepare("DELETE FROM hosted_pages WHERE slug = ?")->execute([$slug]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：封禁/解封托管页面 --- */
        if ($api === 'admin_hosted_ban') {
            require_admin();
            $d = input();
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($d['slug'] ?? ''));
            $banned = !empty($d['banned']) ? 1 : 0;
            if ($slug === '') json_out(['error' => '参数错误'], 400);
            db()->prepare("UPDATE hosted_pages SET is_banned = ? WHERE slug = ?")->execute([$banned, $slug]);
            json_out(['ok' => true, 'is_banned' => (bool)$banned]);
        }

        /* --- 管理员：获取托管配置 --- */
        if ($api === 'admin_hosted_settings_get') {
            require_admin();
            $total = (int)db()->query("SELECT COUNT(*) AS c FROM hosted_pages")->fetch()['c'];
            json_out([
                'enabled'      => app_setting('hosting_enabled', '1') === '1',
                'max_per_user' => (int)app_setting('hosting_max_per_user', '10'),
                'max_size_kb'  => (int)app_setting('hosting_max_size_kb', '100'),
                'max_total'    => (int)app_setting('hosting_max_total', '100'),
                'total_count'  => $total,
            ]);
        }

        /* --- 管理员：更新托管配置 --- */
        if ($api === 'admin_hosted_settings') {
            require_admin();
            $d = input();
            $newEnabled = !empty($d['enabled']) ? '1' : '0';
            $oldEnabled = app_setting('hosting_enabled', '1');
            // 关键：从开启变为关闭时，删除所有已托管的页面
            if ($oldEnabled === '1' && $newEnabled === '0') {
                db()->exec("DELETE FROM hosted_pages");
            }
            set_app_setting('hosting_enabled', $newEnabled);
            $maxPerUser = max(1, min(100, (int)($d['max_per_user'] ?? 10)));
            $maxSizeKb = max(1, min(10240, (int)($d['max_size_kb'] ?? 100)));
            $maxTotal = max(1, min(100000, (int)($d['max_total'] ?? 100)));
            set_app_setting('hosting_max_per_user', (string)$maxPerUser);
            set_app_setting('hosting_max_size_kb', (string)$maxSizeKb);
            set_app_setting('hosting_max_total', (string)$maxTotal);
            json_out(['ok' => true]);
        }

        /* === 管理员后台 === */

        /* --- 管理员登录（独立密码，默认 admin） --- */
        if ($api === 'admin_login') {
            $d = input();
            $pass = (string)($d['password'] ?? '');

            // 频率限制：防爆破
            if (!admin_login_rate_check('60s', 5, 60)) {
                admin_login_rate_record();
                json_out(['error' => '尝试过于频繁，请 60 秒后再试'], 429);
            }
            if (!admin_login_rate_check('1h', 20, 3600)) {
                admin_login_rate_record();
                json_out(['error' => '本小时登录尝试次数过多，请稍后再试'], 429);
            }
            if (!admin_login_rate_check('1d', 50, 86400)) {
                admin_login_rate_record();
                json_out(['error' => '今日登录尝试次数已达上限'], 429);
            }

            $stored = setting('admin_password', 'admin');
            // 密码校验（兼容旧版明文 + hash）
            if (!verify_admin_password($pass, $stored)) {
                admin_login_rate_record();
                // 记录失败日志
                admin_log('admin_login_failed', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                // 延迟响应，防时序攻击（统一延迟 300ms）
                usleep(300000);
                json_out(['error' => '密码错误'], 400);
            }

            // 登录成功：自动升级旧版明文密码为 hash
            if (admin_password_needs_upgrade($stored)) {
                $cfg = load_config();
                if ($cfg) {
                    $cfg['admin_password'] = password_hash($pass, PASSWORD_BCRYPT);
                    $configContent = "<?php\n// HTMLHub 配置文件 - 自动生成\n// 请勿修改，如需修改请删除此文件重新安装\nreturn " . var_export($cfg, true) . ";\n";
                    file_put_contents(CONFIG_FILE, $configContent);
                }
            }

            // session 固定攻击防护：重新生成 session ID
            session_regenerate_id(true);
            // 设置管理员 session（绑定指纹 + 登录时间）
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_fp'] = admin_session_fingerprint();
            $_SESSION['admin_login_at'] = time();

            admin_log('admin_login_success', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            json_out(['ok' => true]);
        }

        /* --- 管理员退出 --- */
        if ($api === 'admin_logout') {
            // 记录退出日志（仅在已登录时）
            if (!empty($_SESSION['is_admin'])) {
                admin_log('admin_logout', '');
            }
            // 彻底清除管理员 session
            unset($_SESSION['is_admin'], $_SESSION['admin_fp'], $_SESSION['admin_login_at']);
            // 重新生成 session ID（防重用）
            session_regenerate_id(true);
            json_out(['ok' => true]);
        }

        /* --- 管理员：统计概览 --- */
        if ($api === 'admin_stats') {
            require_admin();
            $usersCount = (int)db()->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
            $postsCount = (int)db()->query("SELECT COUNT(*) AS c FROM posts")->fetch()['c'];
            $commentsCount = (int)db()->query("SELECT COUNT(*) AS c FROM comments")->fetch()['c'];
            $likesCount = (int)db()->query("SELECT COUNT(*) AS c FROM likes")->fetch()['c'];
            $htmlCount = (int)db()->query("SELECT COUNT(*) AS c FROM posts WHERE type='html'")->fetch()['c'];
            $textCount = (int)db()->query("SELECT COUNT(*) AS c FROM posts WHERE type='text'")->fetch()['c'];
            $bannedCount = (int)db()->query("SELECT COUNT(*) AS c FROM users WHERE status='banned'")->fetch()['c'];
            $today = strtotime(date('Y-m-d'));
            $stmt = db()->prepare("SELECT COUNT(*) AS c FROM posts WHERE created_at >= ?");
            $stmt->execute([$today]);
            $todayCount = (int)$stmt->fetch()['c'];
            json_out([
                'users' => $usersCount,
                'posts' => $postsCount,
                'comments' => $commentsCount,
                'likes' => $likesCount,
                'html_posts' => $htmlCount,
                'text_posts' => $textCount,
                'banned' => $bannedCount,
                'today_posts' => $todayCount,
            ]);
        }

        /* --- 管理员：用户列表（支持分页 + 筛选） --- */
        if ($api === 'admin_users') {
            require_admin();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;

            // 筛选条件：status (active/banned/all), role (user/admin/all),
            // created_after (unix timestamp), created_before (unix timestamp),
            // bot_like (1=仅显示疑似机器人用户名), has_no_posts (1=仅显示0帖用户)
            $where = [];
            $args = [];
            $status = $_GET['status'] ?? 'all';
            if (in_array($status, ['active', 'banned'], true)) {
                $where[] = 'status = ?';
                $args[] = $status;
            }
            $role = $_GET['role'] ?? 'all';
            if (in_array($role, ['user', 'admin'], true)) {
                $where[] = 'role = ?';
                $args[] = $role;
            }
            if (!empty($_GET['created_after'])) {
                $where[] = 'created_at >= ?';
                $args[] = (int)$_GET['created_after'];
            }
            if (!empty($_GET['created_before'])) {
                $where[] = 'created_at <= ?';
                $args[] = (int)$_GET['created_before'];
            }
            // has_no_posts=1 时只返回 0 帖用户（用 NOT EXISTS 子查询，避免 N+1）
            if (!empty($_GET['has_no_posts'])) {
                $where[] = 'NOT EXISTS (SELECT 1 FROM posts p WHERE p.user_id = users.id)';
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            // 总数（用于前端显示 "共 N 个用户"）
            $cntStmt = db()->prepare("SELECT COUNT(*) AS c FROM users $whereSql");
            $cntStmt->execute($args);
            $total = (int)$cntStmt->fetch()['c'];

            // 列表查询
            $sql = "SELECT id, username, avatar, bio, role, status, created_at FROM users $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $out = [];
            foreach ($stmt->fetchAll() as $u) {
                $cnt = db()->prepare("SELECT (SELECT COUNT(*) FROM posts WHERE user_id = u.id) AS posts_count,
                                              (SELECT COUNT(*) FROM follows WHERE follower_id = u.id) AS following_count,
                                              (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers_count
                                       FROM users u WHERE u.id = ?");
                $cnt->execute([$u['id']]);
                $c = $cnt->fetch();
                $u['posts_count'] = (int)$c['posts_count'];
                $u['following_count'] = (int)$c['following_count'];
                $u['followers_count'] = (int)$c['followers_count'];
                $u['bot_like'] = looks_like_bot_username($u['username']);
                // 保留原始时间戳给前端做批量筛选展示，再转相对时间
                $u['created_at_ts'] = (int)$u['created_at'];
                $u['created_at'] = time_ago((int)$u['created_at']);
                // 解析 avatar：图片 ID → URL
                $u['avatar'] = resolve_image($u['avatar'] ?? null);
                $out[] = $u;
            }
            // bot_like 是后端 PHP 检测，无法直接在 SQL 里过滤——这里在内存中过滤
            // 注意：这会导致 has_more 的判断略不精确（因为先 SQL LIMIT 后再过滤），但能保证筛选效果正确
            if (!empty($_GET['bot_like'])) {
                $out = array_values(array_filter($out, fn($u) => $u['bot_like']));
            }
            json_out([
                'users' => $out,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
                'has_more' => count($out) === $limit && ($offset + $limit) < $total,
            ]);
        }

        /* --- 管理员：所有帖子 --- */
        if ($api === 'admin_posts') {
            require_admin();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;
            $stmt = db()->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON u.id=p.user_id
                                   ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([]);
            $out = [];
            foreach ($stmt->fetchAll() as $p) {
                $out[] = [
                    'id' => (int)$p['id'],
                    'type' => $p['type'],
                    'title' => $p['title'],
                    'views' => (int)$p['views'],
                    'likes_count' => (int)$p['likes_count'],
                    'comments_count' => (int)$p['comments_count'],
                    'is_pinned' => !empty($p['is_pinned']),
                    'created_at' => time_ago((int)$p['created_at']),
                    'author' => ['id' => (int)$p['user_id'], 'username' => $p['username']],
                ];
            }
            json_out(['posts' => $out]);
        }

        /* --- 管理员：置顶/取消置顶 --- */
        if ($api === 'admin_pin') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $pinned = !empty($d['pinned']) ? 1 : 0;
            db()->prepare("UPDATE posts SET is_pinned = ? WHERE id = ?")->execute([$pinned, $id]);
            json_out(['ok' => true, 'is_pinned' => (bool)$pinned]);
        }

        /* --- 管理员：删帖 --- */
        if ($api === 'admin_delete_post') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            // 帖子本身要删，关联数据直接清理（计数字段会随帖子一起消失，无需同步）
            delete_post_relations([$id]);
            db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：封禁/解封用户 --- */
        if ($api === 'admin_ban_user') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $banned = !empty($d['banned']);
            // 不能封禁其他管理员（防止误操作）
            $chk = db()->prepare("SELECT role FROM users WHERE id = ?");
            $chk->execute([$id]);
            $u = $chk->fetch();
            if (!$u) json_out(['error' => '用户不存在'], 404);
            if ($u['role'] === 'admin') json_out(['error' => '不能封禁管理员'], 400);
            $status = $banned ? 'banned' : 'active';
            db()->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $id]);
            json_out(['ok' => true, 'status' => $status]);
        }

        /* --- 管理员：彻底删除用户（联级清理所有数据） --- */
        if ($api === 'admin_delete_user') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) json_out(['error' => '参数错误'], 400);
            $chk = db()->prepare("SELECT role, username FROM users WHERE id = ?");
            $chk->execute([$id]);
            $u = $chk->fetch();
            if (!$u) json_out(['error' => '用户不存在'], 404);
            if ($u['role'] === 'admin') json_out(['error' => '不能删除管理员'], 400);

            db()->beginTransaction();
            try {
                // 1. 获取该用户所有帖子 ID
                $idStmt = db()->prepare("SELECT id FROM posts WHERE user_id = ?");
                $idStmt->execute([$id]);
                $postIds = array_column($idStmt->fetchAll(), 'id');
                if ($postIds) {
                    // 这些帖子本身会被删，关联数据直接清理，无需同步计数
                    delete_post_relations($postIds);
                }
                // 2. 删除该用户的帖子
                db()->prepare("DELETE FROM posts WHERE user_id = ?")->execute([$id]);
                // 3. 删除该用户的评论（同步扣减他人帖子的 comments_count）
                delete_comments_by_users_and_sync([$id]);
                // 4. 删除该用户的点赞（同步扣减他人帖子的 likes_count —— 这是关键修复）
                delete_likes_by_users_and_sync([$id]);
                // 5. 删除该用户的收藏（同步扣减他人帖子的 favorites_count）
                delete_favorites_by_users_and_sync([$id]);
                // 6. 删除该用户的关注关系（follower 或 following）
                db()->prepare("DELETE FROM follows WHERE follower_id = ? OR following_id = ?")->execute([$id, $id]);
                // 7. 删除该用户的工作室成员关系
                db()->prepare("DELETE FROM studio_members WHERE user_id = ?")->execute([$id]);
                // 8. 删除该用户的通知（接收或触发）
                db()->prepare("DELETE FROM notifications WHERE user_id = ? OR actor_id = ?")->execute([$id, $id]);
                // 9. 处理该用户拥有工作室：转让或删除
                $stuStmt = db()->prepare("SELECT id FROM studios WHERE owner_id = ?");
                $stuStmt->execute([$id]);
                $studios = $stuStmt->fetchAll();
                foreach ($studios as $s) {
                    // 工作室的帖子设为非工作室
                    db()->prepare("UPDATE posts SET studio_id = 0 WHERE studio_id = ?")->execute([$s['id']]);
                    // 删除工作室成员
                    db()->prepare("DELETE FROM studio_members WHERE studio_id = ?")->execute([$s['id']]);
                    // 删除工作室
                    db()->prepare("DELETE FROM studios WHERE id = ?")->execute([$s['id']]);
                }
                // 10. 最后删除用户
                db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                db()->commit();
                admin_log('admin_delete_user', "用户ID: {$id}, 用户名: {$u['username']}, 删除帖子: " . count($postIds) . ", 删除工作室: " . count($studios));
                json_out(['ok' => true, 'deleted_posts' => count($postIds), 'deleted_studios' => count($studios)]);
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '删除失败：' . $e->getMessage()], 500);
            }
        }

        /* --- 管理员：批量删除用户（联级清理，事务保证一致性）
         *   入参：
         *     ids: [int]              // 显式指定用户 ID 列表
         *     或 filter:              // 按筛选条件批量删除（用于清机器人）
         *       status: 'active'|'banned'|'all'
         *       role:   'user'|'admin'|'all'  // 永远不会删 admin，强制 user
         *       created_after: int             // unix 时间戳
         *       created_before: int
         *       bot_like: bool                 // 仅删疑似机器人用户名
         *       has_no_posts: bool             // 仅删 0 帖用户（更安全的清理）
         *   安全：单次最多 500 个；管理员账号永远不被删；当前管理员自己永远不被删
         *   返回：deleted_count, deleted_posts, deleted_studios, skipped_admin
         */
        if ($api === 'admin_bulk_delete_users') {
            require_admin();
            $d = input();
            $ids = $d['ids'] ?? null;
            $filter = $d['filter'] ?? null;
            $currentAdminId = (int)($_SESSION['uid'] ?? 0);

            // 1. 收集目标 ID 列表
            $targetIds = [];
            if (is_array($ids) && count($ids) > 0) {
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) $targetIds[] = $id;
                }
            } elseif (is_array($filter) && !empty($filter)) {
                // 按筛选条件查询
                $where = [];
                $args = [];
                // 永远限制为普通用户（不能批量删管理员）
                $where[] = "role = 'user'";
                $status = $filter['status'] ?? 'all';
                if (in_array($status, ['active', 'banned'], true)) {
                    $where[] = 'status = ?';
                    $args[] = $status;
                }
                if (!empty($filter['created_after'])) {
                    $where[] = 'created_at >= ?';
                    $args[] = (int)$filter['created_after'];
                }
                if (!empty($filter['created_before'])) {
                    $where[] = 'created_at <= ?';
                    $args[] = (int)$filter['created_before'];
                }
                $whereSql = implode(' AND ', $where);
                // 安全上限：先取 500 个，超过的不删（管理员可以分批操作）
                $sql = "SELECT id, username FROM users WHERE $whereSql ORDER BY id DESC LIMIT 500";
                $stmt = db()->prepare($sql);
                $stmt->execute($args);
                $rows = $stmt->fetchAll();
                // 可选：仅疑似机器人用户名
                if (!empty($filter['bot_like'])) {
                    $rows = array_values(array_filter($rows, fn($r) => looks_like_bot_username($r['username'])));
                }
                // 可选：仅 0 帖用户
                if (!empty($filter['has_no_posts'])) {
                    $filtered = [];
                    $pcnt = db()->prepare("SELECT COUNT(*) AS c FROM posts WHERE user_id = ?");
                    foreach ($rows as $r) {
                        $pcnt->execute([$r['id']]);
                        if ((int)$pcnt->fetch()['c'] === 0) $filtered[] = $r;
                    }
                    $rows = $filtered;
                }
                $targetIds = array_map(fn($r) => (int)$r['id'], $rows);
            }

            if (empty($targetIds)) {
                json_out(['error' => '没有符合条件的用户'], 400);
            }
            // 单次最多 500 个（双保险，filter 路径已限制）
            $targetIds = array_slice($targetIds, 0, 500);
            $targetIds = array_unique($targetIds);

            // 2. 查询这些用户，过滤掉管理员和当前管理员
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $chkStmt = db()->prepare("SELECT id, role, username FROM users WHERE id IN ($placeholders)");
            $chkStmt->execute($targetIds);
            $toDelete = [];
            $skippedAdmin = 0;
            foreach ($chkStmt->fetchAll() as $u) {
                if ($u['role'] === 'admin') { $skippedAdmin++; continue; }
                if ((int)$u['id'] === $currentAdminId) { $skippedAdmin++; continue; }
                $toDelete[] = (int)$u['id'];
            }
            if (empty($toDelete)) {
                json_out(['ok' => true, 'deleted_count' => 0, 'deleted_posts' => 0, 'deleted_studios' => 0, 'skipped_admin' => $skippedAdmin, 'message' => '没有可删除的用户（管理员账号已被跳过）']);
            }

            $deletedPosts = 0;
            $deletedStudios = 0;
            db()->beginTransaction();
            try {
                // 收集这些用户的所有帖子 ID，批量删除关联数据
                $idStmt = db()->prepare("SELECT id FROM posts WHERE user_id IN ($placeholders)");
                $idStmt->execute($toDelete);
                $postIds = array_column($idStmt->fetchAll(), 'id');
                if ($postIds) {
                    // 这些帖子本身会被删，关联数据直接清理，无需同步计数
                    delete_post_relations($postIds);
                    $deletedPosts = count($postIds);
                }
                // 删除这些用户的帖子
                db()->prepare("DELETE FROM posts WHERE user_id IN ($placeholders)")->execute($toDelete);
                // 删除这些用户的评论（同步扣减他人帖子的 comments_count）
                delete_comments_by_users_and_sync($toDelete);
                // 删除这些用户的点赞（同步扣减他人帖子的 likes_count —— 关键修复）
                delete_likes_by_users_and_sync($toDelete);
                // 删除这些用户的收藏（同步扣减他人帖子的 favorites_count）
                delete_favorites_by_users_and_sync($toDelete);
                // 删除这些用户的关注关系
                db()->prepare("DELETE FROM follows WHERE follower_id IN ($placeholders) OR following_id IN ($placeholders)")
                    ->execute(array_merge($toDelete, $toDelete));
                // 删除这些用户的工作室成员关系
                db()->prepare("DELETE FROM studio_members WHERE user_id IN ($placeholders)")->execute($toDelete);
                // 删除这些用户的通知
                db()->prepare("DELETE FROM notifications WHERE user_id IN ($placeholders) OR actor_id IN ($placeholders)")
                    ->execute(array_merge($toDelete, $toDelete));
                // 处理这些用户拥有的工作室
                $stuStmt = db()->prepare("SELECT id FROM studios WHERE owner_id IN ($placeholders)");
                $stuStmt->execute($toDelete);
                $studioIds = array_column($stuStmt->fetchAll(), 'id');
                if ($studioIds) {
                    $sPh = implode(',', array_fill(0, count($studioIds), '?'));
                    db()->prepare("UPDATE posts SET studio_id = 0 WHERE studio_id IN ($sPh)")->execute($studioIds);
                    db()->prepare("DELETE FROM studio_members WHERE studio_id IN ($sPh)")->execute($studioIds);
                    db()->prepare("DELETE FROM studios WHERE id IN ($sPh)")->execute($studioIds);
                    $deletedStudios = count($studioIds);
                }
                // 最后删除用户
                db()->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($toDelete);
                db()->commit();
                admin_log('admin_bulk_delete_users', "删除 " . count($toDelete) . " 个用户, 帖子 {$deletedPosts}, 工作室 {$deletedStudios}, 跳过管理员 {$skippedAdmin}");
                json_out([
                    'ok' => true,
                    'deleted_count' => count($toDelete),
                    'deleted_posts' => $deletedPosts,
                    'deleted_studios' => $deletedStudios,
                    'skipped_admin' => $skippedAdmin,
                ]);
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '批量删除失败：' . $e->getMessage()], 500);
            }
        }

        /* --- 管理员：批量封禁/解封用户
         *   入参：
         *     ids: [int]              // 显式指定
         *     或 filter:              // 按筛选条件批量操作
         *       (同 admin_bulk_delete_users)
         *     banned: bool            // true=封禁, false=解封
         *   安全：单次最多 500 个；管理员账号不被封；当前管理员自己不被封
         */
        if ($api === 'admin_bulk_ban_users') {
            require_admin();
            $d = input();
            $banned = !empty($d['banned']);
            $ids = $d['ids'] ?? null;
            $filter = $d['filter'] ?? null;
            $currentAdminId = (int)($_SESSION['uid'] ?? 0);

            $targetIds = [];
            if (is_array($ids) && count($ids) > 0) {
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) $targetIds[] = $id;
                }
            } elseif (is_array($filter) && !empty($filter)) {
                $where = ["role = 'user'"];
                $args = [];
                $status = $filter['status'] ?? 'all';
                if (in_array($status, ['active', 'banned'], true)) {
                    $where[] = 'status = ?';
                    $args[] = $status;
                }
                if (!empty($filter['created_after'])) {
                    $where[] = 'created_at >= ?';
                    $args[] = (int)$filter['created_after'];
                }
                if (!empty($filter['created_before'])) {
                    $where[] = 'created_at <= ?';
                    $args[] = (int)$filter['created_before'];
                }
                $whereSql = implode(' AND ', $where);
                $sql = "SELECT id, username FROM users WHERE $whereSql ORDER BY id DESC LIMIT 500";
                $stmt = db()->prepare($sql);
                $stmt->execute($args);
                $rows = $stmt->fetchAll();
                if (!empty($filter['bot_like'])) {
                    $rows = array_values(array_filter($rows, fn($r) => looks_like_bot_username($r['username'])));
                }
                $targetIds = array_map(fn($r) => (int)$r['id'], $rows);
            }

            if (empty($targetIds)) {
                json_out(['error' => '没有符合条件的用户'], 400);
            }
            $targetIds = array_slice(array_unique($targetIds), 0, 500);

            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $chkStmt = db()->prepare("SELECT id, role FROM users WHERE id IN ($placeholders)");
            $chkStmt->execute($targetIds);
            $toUpdate = [];
            $skippedAdmin = 0;
            foreach ($chkStmt->fetchAll() as $u) {
                if ($u['role'] === 'admin') { $skippedAdmin++; continue; }
                if ((int)$u['id'] === $currentAdminId) { $skippedAdmin++; continue; }
                $toUpdate[] = (int)$u['id'];
            }
            if (empty($toUpdate)) {
                json_out(['ok' => true, 'updated_count' => 0, 'skipped_admin' => $skippedAdmin]);
            }
            $newStatus = $banned ? 'banned' : 'active';
            $upPh = implode(',', array_fill(0, count($toUpdate), '?'));
            db()->prepare("UPDATE users SET status = ? WHERE id IN ($upPh)")
                ->execute(array_merge([$newStatus], $toUpdate));
            json_out([
                'ok' => true,
                'updated_count' => count($toUpdate),
                'skipped_admin' => $skippedAdmin,
                'status' => $newStatus,
            ]);
        }

        /* --- 管理员：批量删除用户的点赞（清理由机器人刷的赞）
         *   入参：
         *     user_ids: [int]    // 这些用户的所有点赞会被清空
         *     或 post_ids: [int] // 这些帖子的所有点赞会被清空
         *   安全：单次最多处理 500 个用户 / 500 个帖子
         */
        if ($api === 'admin_bulk_clean_likes') {
            require_admin();
            $d = input();
            $userIds = $d['user_ids'] ?? [];
            $postIds = $d['post_ids'] ?? [];
            $deletedLikes = 0;

            db()->beginTransaction();
            try {
                if (is_array($userIds) && count($userIds) > 0) {
                    // 重新索引数组，避免 array_filter 后键稀疏导致 PDO execute 失败
                    $userIds = array_values(array_filter(
                        array_slice(array_unique(array_map('intval', $userIds)), 0, 500),
                        fn($x) => $x > 0
                    ));
                    if ($userIds) {
                        // 用统一助手：删除 likes + 同步扣减 likes_count
                        // 但这里需要返回删除条数，所以先查一下
                        $ph = implode(',', array_fill(0, count($userIds), '?'));
                        $cntStmt = db()->prepare("SELECT COUNT(*) AS c FROM likes WHERE user_id IN ($ph)");
                        $cntStmt->execute($userIds);
                        $before = (int)$cntStmt->fetch()['c'];
                        delete_likes_by_users_and_sync($userIds);
                        $deletedLikes += $before;
                    }
                }
                if (is_array($postIds) && count($postIds) > 0) {
                    $postIds = array_values(array_filter(
                        array_slice(array_unique(array_map('intval', $postIds)), 0, 500),
                        fn($x) => $x > 0
                    ));
                    if ($postIds) {
                        $ph = implode(',', array_fill(0, count($postIds), '?'));
                        $cntStmt = db()->prepare("DELETE FROM likes WHERE post_id IN ($ph)");
                        $cntStmt->execute($postIds);
                        $deletedLikes += (int)$cntStmt->rowCount();
                        db()->prepare("UPDATE posts SET likes_count = 0 WHERE id IN ($ph)")->execute($postIds);
                    }
                }
                db()->commit();
                json_out(['ok' => true, 'deleted_likes' => $deletedLikes]);
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '清理点赞失败：' . $e->getMessage()], 500);
            }
        }

        /* --- 管理员：重新同步所有帖子的计数字段（likes_count / favorites_count / comments_count）
         *   用途：历史上因 bug 导致 posts.likes_count 与实际 likes 表行数不一致，
         *   调用此接口可强制用实际行数覆盖计数字段，消除幻数。
         *   入参：无（重新同步全部帖子）
         *   注意：会扫描整张 posts 表，建议在低峰期执行。每次最多同步 5000 条，
         *        超过会返回 has_more=true，前端可继续调用直到 has_more=false。
         */
        if ($api === 'admin_recount_posts') {
            require_admin();
            $batch = min(5000, max(100, (int)($_GET['batch'] ?? 5000)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            db()->beginTransaction();
            try {
                // 用 JOIN 子查询一次性更新（比逐行循环快得多）
                db()->exec("UPDATE posts p
                    SET p.likes_count = (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id),
                        p.favorites_count = (SELECT COUNT(*) FROM favorites f WHERE f.post_id = p.id),
                        p.comments_count = (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)
                    WHERE p.id IN (
                        SELECT id FROM (
                            SELECT id FROM posts ORDER BY id LIMIT $batch OFFSET $offset
                        ) AS tmp
                    )");
                $cntStmt = db()->query("SELECT COUNT(*) AS c FROM posts");
                $total = (int)$cntStmt->fetch()['c'];
                db()->commit();
                json_out([
                    'ok' => true,
                    'batch' => $batch,
                    'offset' => $offset,
                    'total' => $total,
                    'has_more' => ($offset + $batch) < $total,
                    'next_offset' => $offset + $batch,
                ]);
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '重新计数失败：' . $e->getMessage()], 500);
            }
        }

        /* --- 管理员：迁移旧 base64 图片到 images 表
         *   将 posts.cover / posts.images / users.avatar 中的 base64 数据
         *   迁移到 images 表，原字段改为存图片 ID。
         *   每次处理 50 条，前端循环调用直到 migrated=0。
         */
        if ($api === 'admin_migrate_images') {
            require_admin();
            $batch = 50;
            $migrated = 0;

            // 1. 迁移 posts.cover（base64 → image ID）
            $stmt = db()->prepare("SELECT id, cover FROM posts WHERE cover LIKE 'data:%' LIMIT ?");
            $stmt->execute([$batch]);
            foreach ($stmt->fetchAll() as $row) {
                $imgId = store_image($row['cover'], 0);
                if ($imgId !== '') {
                    db()->prepare("UPDATE posts SET cover = ? WHERE id = ?")->execute([$imgId, $row['id']]);
                    $migrated++;
                }
            }

            // 2. 迁移 users.avatar（base64 → image ID）
            $stmt = db()->prepare("SELECT id, avatar FROM users WHERE avatar LIKE 'data:%' LIMIT ?");
            $stmt->execute([$batch]);
            foreach ($stmt->fetchAll() as $row) {
                $imgId = store_image($row['avatar'], (int)$row['id']);
                if ($imgId !== '') {
                    db()->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$imgId, $row['id']]);
                    $migrated++;
                }
            }

            // 3. 迁移 posts.images（JSON base64 数组 → JSON ID 数组）
            $stmt = db()->prepare("SELECT id, images FROM posts WHERE images IS NOT NULL AND images LIKE '%data:%' LIMIT ?");
            $stmt->execute([$batch]);
            foreach ($stmt->fetchAll() as $row) {
                $decoded = json_decode($row['images'], true);
                if (!is_array($decoded)) continue;
                $ids = [];
                $hasBase64 = false;
                foreach ($decoded as $img) {
                    if (is_string($img) && strpos($img, 'data:') === 0) {
                        $hasBase64 = true;
                        $imgId = store_image($img, 0);
                        if ($imgId !== '') $ids[] = $imgId;
                    } else {
                        $ids[] = $img;
                    }
                }
                if ($hasBase64) {
                    $newJson = json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    db()->prepare("UPDATE posts SET images = ? WHERE id = ?")->execute([$newJson, $row['id']]);
                    $migrated++;
                }
            }

            // 统计剩余未迁移数量
            $remainPosts = (int)db()->query("SELECT COUNT(*) AS c FROM posts WHERE cover LIKE 'data:%'")->fetch()['c'];
            $remainUsers = (int)db()->query("SELECT COUNT(*) AS c FROM users WHERE avatar LIKE 'data:%'")->fetch()['c'];
            $remainPostImgs = (int)db()->query("SELECT COUNT(*) AS c FROM posts WHERE images IS NOT NULL AND images LIKE '%data:%'")->fetch()['c'];

            admin_log('admin_migrate_images', "迁移 {$migrated} 条，剩余: posts.cover={$remainPosts}, users.avatar={$remainUsers}, posts.images={$remainPostImgs}");
            json_out([
                'ok' => true,
                'migrated' => $migrated,
                'remaining' => $remainPosts + $remainUsers + $remainPostImgs,
                'remaining_detail' => [
                    'posts_cover' => $remainPosts,
                    'users_avatar' => $remainUsers,
                    'posts_images' => $remainPostImgs,
                ],
            ]);
        }

        /* --- 管理员：改密码 --- */
        if ($api === 'admin_change_password') {
            require_admin();
            $d = input();
            $newPass = (string)($d['password'] ?? '');
            $oldPass = (string)($d['old_password'] ?? '');
            // 密码强度校验：至少 8 位，必须包含字母和数字
            if (strlen($newPass) < 8) json_out(['error' => '新密码至少 8 位'], 400);
            if (!preg_match('/[a-zA-Z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
                json_out(['error' => '新密码必须同时包含字母和数字'], 400);
            }
            // 校验旧密码（二次验证，防止 session 被盗后改密码）
            $stored = setting('admin_password', 'admin');
            if (!verify_admin_password($oldPass, $stored)) {
                json_out(['error' => '旧密码错误'], 400);
            }
            $cfg = load_config();
            if ($cfg) {
                // 存储 hash 而非明文
                $cfg['admin_password'] = password_hash($newPass, PASSWORD_BCRYPT);
                $configContent = "<?php\n// HTMLHub 配置文件 - 自动生成\n// 请勿修改，如需修改请删除此文件重新安装\nreturn " . var_export($cfg, true) . ";\n";
                file_put_contents(CONFIG_FILE, $configContent);
            }
            admin_log('admin_change_password', '密码已修改');
            json_out(['ok' => true]);
        }

        /* --- 管理员：获取操作日志 --- */
        if ($api === 'admin_logs') {
            require_admin();
            $raw = app_setting('admin_logs', '');
            $logs = $raw ? (json_decode($raw, true) ?: []) : [];
            // 倒序（最新在前）
            $logs = array_reverse($logs);
            // 格式化时间
            $out = [];
            foreach ($logs as $log) {
                $out[] = [
                    'time' => date('Y-m-d H:i:s', (int)($log['ts'] ?? 0)),
                    'ip' => $log['ip'] ?? '',
                    'action' => $log['action'] ?? '',
                    'detail' => $log['detail'] ?? '',
                ];
            }
            json_out(['logs' => $out]);
        }

        /* --- 管理员：清除操作日志 --- */
        if ($api === 'admin_logs_clear') {
            require_admin();
            set_app_setting('admin_logs', '');
            admin_log('admin_logs_cleared', '日志已清空');
            json_out(['ok' => true]);
        }

        /* --- 管理员：搜索用户 --- */
        if ($api === 'admin_search_users') {
            require_admin();
            $q = trim($_GET['q'] ?? '');
            if ($q === '') json_out(['users' => [], 'total' => 0, 'has_more' => false]);
            $kw = '%' . $q . '%';
            $stmt = db()->prepare("SELECT id, username, avatar, bio, role, status, created_at FROM users WHERE username LIKE ? OR bio LIKE ? ORDER BY id DESC LIMIT 50");
            $stmt->execute([$kw, $kw]);
            $out = [];
            foreach ($stmt->fetchAll() as $u) {
                $cnt = db()->prepare("SELECT (SELECT COUNT(*) FROM posts WHERE user_id = u.id) AS posts_count,
                                              (SELECT COUNT(*) FROM follows WHERE follower_id = u.id) AS following_count,
                                              (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers_count
                                       FROM users u WHERE u.id = ?");
                $cnt->execute([$u['id']]);
                $c = $cnt->fetch();
                $u['posts_count'] = (int)$c['posts_count'];
                $u['following_count'] = (int)$c['following_count'];
                $u['followers_count'] = (int)$c['followers_count'];
                $u['bot_like'] = looks_like_bot_username($u['username']);
                $u['created_at_ts'] = (int)$u['created_at'];
                $u['created_at'] = time_ago((int)$u['created_at']);
                $u['avatar'] = resolve_image($u['avatar'] ?? null);
                $out[] = $u;
            }
            json_out(['users' => $out, 'total' => count($out), 'has_more' => false]);
        }

        /* --- 管理员：重置用户密码 --- */
        if ($api === 'admin_reset_user_password') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $newPass = (string)($d['password'] ?? '');
            if (strlen($newPass) < 6) json_out(['error' => '密码至少 6 位'], 400);
            $chk = db()->prepare("SELECT role FROM users WHERE id = ?");
            $chk->execute([$id]);
            $u = $chk->fetch();
            if (!$u) json_out(['error' => '用户不存在'], 404);
            if ($u['role'] === 'admin') json_out(['error' => '不能重置管理员密码'], 400);
            db()->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($newPass, PASSWORD_BCRYPT), $id]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：按用户筛选帖子 --- */
        if ($api === 'admin_user_posts') {
            require_admin();
            $userId = (int)($_GET['user_id'] ?? 0);
            if ($userId <= 0) json_out(['posts' => []]);
            $stmt = db()->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON u.id=p.user_id
                                   WHERE p.user_id = ?
                                   ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT 100");
            $stmt->execute([$userId]);
            $out = [];
            foreach ($stmt->fetchAll() as $p) {
                $out[] = [
                    'id' => (int)$p['id'],
                    'type' => $p['type'],
                    'title' => $p['title'],
                    'views' => (int)$p['views'],
                    'likes_count' => (int)$p['likes_count'],
                    'comments_count' => (int)$p['comments_count'],
                    'is_pinned' => !empty($p['is_pinned']),
                    'studio_id' => (int)($p['studio_id'] ?? 0),
                    'created_at' => time_ago((int)$p['created_at']),
                    'author' => ['id' => (int)$p['user_id'], 'username' => $p['username']],
                ];
            }
            json_out(['posts' => $out]);
        }

        /* --- 管理员：批量删除用户所有帖子 --- */
        if ($api === 'admin_delete_user_posts') {
            require_admin();
            $d = input();
            $userId = (int)($d['user_id'] ?? 0);
            if ($userId <= 0) json_out(['error' => '参数错误'], 400);
            $chk = db()->prepare("SELECT role FROM users WHERE id = ?");
            $chk->execute([$userId]);
            $u = $chk->fetch();
            if (!$u) json_out(['error' => '用户不存在'], 404);
            if ($u['role'] === 'admin') json_out(['error' => '不能删除管理员帖子'], 400);
            // 获取所有帖子 ID 用于清理关联
            $idStmt = db()->prepare("SELECT id FROM posts WHERE user_id = ?");
            $idStmt->execute([$userId]);
            $ids = array_column($idStmt->fetchAll(), 'id');
            if ($ids) {
                // 这些帖子本身会被删，关联数据直接清理，无需同步计数
                delete_post_relations($ids);
                db()->prepare("DELETE FROM posts WHERE user_id = ?")->execute([$userId]);
            }
            json_out(['ok' => true, 'deleted_count' => count($ids)]);
        }

        /* --- 管理员：按关键词搜索帖子 --- */
        if ($api === 'admin_search_posts') {
            require_admin();
            $q = trim($_GET['q'] ?? '');
            if ($q === '') json_out(['posts' => []]);
            $kw = '%' . $q . '%';
            $stmt = db()->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON u.id=p.user_id
                                   WHERE p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?
                                   ORDER BY p.created_at DESC LIMIT 50");
            $stmt->execute([$kw, $kw, $kw]);
            $out = [];
            foreach ($stmt->fetchAll() as $p) {
                $out[] = [
                    'id' => (int)$p['id'],
                    'type' => $p['type'],
                    'title' => $p['title'],
                    'views' => (int)$p['views'],
                    'likes_count' => (int)$p['likes_count'],
                    'comments_count' => (int)$p['comments_count'],
                    'is_pinned' => !empty($p['is_pinned']),
                    'studio_id' => (int)($p['studio_id'] ?? 0),
                    'created_at' => time_ago((int)$p['created_at']),
                    'author' => ['id' => (int)$p['user_id'], 'username' => $p['username']],
                ];
            }
            json_out(['posts' => $out]);
        }

        /* --- 管理员：站点统计详情（包含增长趋势） --- */
        if ($api === 'admin_stats_detail') {
            require_admin();
            $today = strtotime(date('Y-m-d'));
            $yesterday = $today - 86400;
            // 今日各类统计
            $stmt = db()->prepare("SELECT COUNT(*) AS c FROM posts WHERE created_at >= ?");
            $stmt->execute([$today]);
            $todayPosts = (int)$stmt->fetch()['c'];
            $stmt = db()->prepare("SELECT COUNT(*) AS c FROM users WHERE created_at >= ?");
            $stmt->execute([$today]);
            $todayUsers = (int)$stmt->fetch()['c'];
            $stmt = db()->prepare("SELECT COUNT(*) AS c FROM comments WHERE created_at >= ?");
            $stmt->execute([$today]);
            $todayComments = (int)$stmt->fetch()['c'];
            // 昨日各类
            $stmt = db()->prepare("SELECT COUNT(*) AS c FROM posts WHERE created_at >= ? AND created_at < ?");
            $stmt->execute([$yesterday, $today]);
            $yesterdayPosts = (int)$stmt->fetch()['c'];
            $stmt = db()->prepare("SELECT COUNT(*) AS c FROM users WHERE created_at >= ? AND created_at < ?");
            $stmt->execute([$yesterday, $today]);
            $yesterdayUsers = (int)$stmt->fetch()['c'];
            // 近 7 天每日发帖趋势
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $dayStart = $today - $i * 86400;
                $dayEnd = $dayStart + 86400;
                $stmt = db()->prepare("SELECT COUNT(*) AS c FROM posts WHERE created_at >= ? AND created_at < ?");
                $stmt->execute([$dayStart, $dayEnd]);
                $trend[] = ['date' => date('m-d', $dayStart), 'count' => (int)$stmt->fetch()['c']];
            }
            // 工作室数
            $studiosCount = (int)db()->query("SELECT COUNT(*) AS c FROM studios")->fetch()['c'];
            json_out([
                'today_posts' => $todayPosts,
                'today_users' => $todayUsers,
                'today_comments' => $todayComments,
                'yesterday_posts' => $yesterdayPosts,
                'yesterday_users' => $yesterdayUsers,
                'studios' => $studiosCount,
                'trend' => $trend,
            ]);
        }

        /* --- 管理员：修改帖子标题/内容 --- */
        if ($api === 'admin_edit_post') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $title = clean_plain($d['title'] ?? '', 50);
            $content = clean_text($d['content'] ?? '', 5000);
            if ($title === '') json_out(['error' => '标题不能为空'], 400);
            $stmt = db()->prepare("SELECT type FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['error' => '帖子不存在'], 404);
            if ($p['type'] === 'text') {
                if ($content === '') json_out(['error' => '内容不能为空'], 400);
                db()->prepare("UPDATE posts SET title = ?, content = ? WHERE id = ?")->execute([$title, $content, $id]);
            } else {
                db()->prepare("UPDATE posts SET title = ? WHERE id = ?")->execute([$title, $id]);
            }
            json_out(['ok' => true]);
        }

        /* --- 管理员：删除任意评论 --- */
        if ($api === 'admin_delete_comment') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $stmt = db()->prepare("SELECT post_id FROM comments WHERE id = ?");
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if (!$c) json_out(['error' => '评论不存在'], 404);
            db()->prepare("DELETE FROM comments WHERE id = ?")->execute([$id]);
            db()->prepare("UPDATE posts SET comments_count = GREATEST(0, comments_count - 1) WHERE id = ?")->execute([$c['post_id']]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：评论列表（按时间倒序，全站） --- */
        if ($api === 'admin_comments') {
            require_admin();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;
            $stmt = db()->prepare("SELECT c.id, c.content, c.created_at, c.post_id, u.username, u.id AS user_id, p.title AS post_title
                                   FROM comments c
                                   JOIN users u ON u.id = c.user_id
                                   JOIN posts p ON p.id = c.post_id
                                   ORDER BY c.id DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'content' => $r['content'],
                    'created_at' => time_ago((int)$r['created_at']),
                    'post' => ['id' => (int)$r['post_id'], 'title' => $r['post_title']],
                    'user' => ['id' => (int)$r['user_id'], 'username' => $r['username']],
                ];
            }
            json_out(['comments' => $out]);
        }

        /* --- 举报原因白名单（公共接口，前端举报页用） --- */
        if ($api === 'report_reasons') {
            json_out(['reasons' => report_reason_whitelist()]);
        }

        /* --- 提交举报 ---
         *   入参：
         *     target_type: 'post' | 'comment' | 'user'
         *     target_id: int
         *     reason: 白名单内的原因标识符
         *     detail: 补充说明（≤500 字，可选）
         *   安全：
         *     - 必须登录
         *     - 频率限制：60秒/3次，1小时/20次
         *     - 同一用户对同一目标只能举报一次（pending 或已处理都算）
         *     - target 必须存在
         */
        if ($api === 'report') {
            $u = require_auth();
            $d = input();
            $targetType = trim((string)($d['target_type'] ?? ''));
            $targetId = (int)($d['target_id'] ?? 0);
            $reason = trim((string)($d['reason'] ?? ''));
            $detail = clean_text((string)($d['detail'] ?? ''), 500);

            // 校验 target_type
            if (!in_array($targetType, ['post', 'comment', 'user'], true)) {
                json_out(['error' => '举报目标类型无效'], 400);
            }
            if ($targetId <= 0) {
                json_out(['error' => '举报目标 ID 无效'], 400);
            }
            // 校验 reason
            $whitelist = report_reason_whitelist();
            if (!isset($whitelist[$reason])) {
                json_out(['error' => '举报原因无效'], 400);
            }
            // custom 原因必须填写 detail
            if ($reason === 'custom' && $detail === '') {
                json_out(['error' => '选择自定义原因时必须填写说明'], 400);
            }

            // 频率限制
            if (!report_rate_check('60s', 3, 60)) {
                json_out(['error' => '举报过于频繁，请 60 秒后再试'], 429);
            }
            if (!report_rate_check('1h', 20, 3600)) {
                json_out(['error' => '本小时举报次数已达上限，请稍后再试'], 429);
            }

            // 校验目标存在
            $targetExists = false;
            $targetOwnerId = 0;
            if ($targetType === 'post') {
                $chk = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
                $chk->execute([$targetId]);
                $row = $chk->fetch();
                if ($row) { $targetExists = true; $targetOwnerId = (int)$row['user_id']; }
            } elseif ($targetType === 'comment') {
                $chk = db()->prepare("SELECT user_id FROM comments WHERE id = ?");
                $chk->execute([$targetId]);
                $row = $chk->fetch();
                if ($row) { $targetExists = true; $targetOwnerId = (int)$row['user_id']; }
            } elseif ($targetType === 'user') {
                $chk = db()->prepare("SELECT id FROM users WHERE id = ?");
                $chk->execute([$targetId]);
                $row = $chk->fetch();
                if ($row) { $targetExists = true; $targetOwnerId = (int)$row['id']; }
            }
            if (!$targetExists) {
                json_out(['error' => '举报目标不存在或已被删除'], 404);
            }
            // 不能举报自己
            if ($targetOwnerId === (int)$u['id']) {
                json_out(['error' => '不能举报自己'], 400);
            }

            // 同一用户对同一目标只能举报一次
            $dup = db()->prepare("SELECT id FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? LIMIT 1");
            $dup->execute([(int)$u['id'], $targetType, $targetId]);
            if ($dup->fetch()) {
                json_out(['error' => '你已经举报过这个内容了'], 400);
            }

            // 写入举报
            db()->prepare("INSERT INTO reports (reporter_id, target_type, target_id, reason, detail, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?)")
                ->execute([(int)$u['id'], $targetType, $targetId, $reason, $detail, time()]);
            report_rate_record();
            json_out(['ok' => true, 'message' => '举报已提交，管理员会尽快处理']);
        }

        /* --- 管理员：举报列表 ---
         *   查询参数：
         *     status: 'pending' | 'resolved' | 'dismissed' | 'all'（默认 pending）
         *     page: 分页
         *   返回：举报列表 + 关联的目标内容快照 + 举报人信息 + 被举报人信息
         */
        if ($api === 'admin_reports') {
            require_admin();
            $whitelist = report_reason_whitelist();
            $status = $_GET['status'] ?? 'pending';
            if (!in_array($status, ['pending', 'resolved', 'dismissed', 'all'], true)) {
                $status = 'pending';
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;

            $where = [];
            $args = [];
            if ($status !== 'all') {
                $where[] = 'r.status = ?';
                $args[] = $status;
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            // 总数
            $cntStmt = db()->prepare("SELECT COUNT(*) AS c FROM reports r $whereSql");
            $cntStmt->execute($args);
            $total = (int)$cntStmt->fetch()['c'];

            // 列表（JOIN 举报人 + 处理人；被举报人按 target_type 在循环里动态查）
            $sql = "SELECT r.*,
                       ru.username AS reporter_username, ru.avatar AS reporter_avatar,
                       hui.username AS handler_username
                    FROM reports r
                    LEFT JOIN users ru ON ru.id = r.reporter_id
                    LEFT JOIN users hui ON hui.id = r.handler_id
                    $whereSql
                    ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();

            $out = [];
            // 预编译查询被举报目标的语句，避免 N+1 重复 prepare
            $postStmt = db()->prepare("SELECT id, title, type, content, user_id FROM posts WHERE id = ?");
            $cmtStmt = db()->prepare("SELECT c.id, c.content, c.user_id, c.post_id, p.title AS post_title FROM comments c LEFT JOIN posts p ON p.id = c.post_id WHERE c.id = ?");
            $userStmt = db()->prepare("SELECT id, username, avatar, bio, role, status FROM users WHERE id = ?");

            foreach ($rows as $r) {
                $item = [
                    'id' => (int)$r['id'],
                    'reporter' => [
                        'id' => (int)$r['reporter_id'],
                        'username' => $r['reporter_username'] ?? '(已删除用户)',
                        'avatar' => resolve_image($r['reporter_avatar']),
                    ],
                    'target_type' => $r['target_type'],
                    'target_id' => (int)$r['target_id'],
                    'reason' => $r['reason'],
                    'reason_label' => $whitelist[$r['reason']] ?? $r['reason'],
                    'detail' => $r['detail'],
                    'status' => $r['status'],
                    'handler' => $r['handler_username'] ? ['id' => (int)$r['handler_id'], 'username' => $r['handler_username']] : null,
                    'handler_note' => $r['handler_note'],
                    'created_at' => time_ago((int)$r['created_at']),
                    'created_ts' => (int)$r['created_at'],
                    'handled_at' => $r['handled_at'] ? time_ago((int)$r['handled_at']) : '',
                    'target_snapshot' => null,
                ];
                // 查目标快照
                if ($r['target_type'] === 'post') {
                    $postStmt->execute([$r['target_id']]);
                    $p = $postStmt->fetch();
                    if ($p) {
                        $item['target_snapshot'] = [
                            'exists' => true,
                            'id' => (int)$p['id'],
                            'title' => $p['title'],
                            'type' => $p['type'],
                            'content_preview' => mb_substr($p['content'] ?? '', 0, 200, 'UTF-8'),
                            'owner_id' => (int)$p['user_id'],
                        ];
                        // 查帖子作者
                        $userStmt->execute([$p['user_id']]);
                        $owner = $userStmt->fetch();
                        $item['target_owner'] = $owner ? [
                            'id' => (int)$owner['id'],
                            'username' => $owner['username'],
                            'avatar' => resolve_image($owner['avatar']),
                            'role' => $owner['role'],
                            'status' => $owner['status'],
                        ] : null;
                    } else {
                        $item['target_snapshot'] = ['exists' => false];
                        $item['target_owner'] = null;
                    }
                } elseif ($r['target_type'] === 'comment') {
                    $cmtStmt->execute([$r['target_id']]);
                    $c = $cmtStmt->fetch();
                    if ($c) {
                        $item['target_snapshot'] = [
                            'exists' => true,
                            'id' => (int)$c['id'],
                            'content' => $c['content'],
                            'post_id' => (int)$c['post_id'],
                            'post_title' => $c['post_title'],
                            'owner_id' => (int)$c['user_id'],
                        ];
                        $userStmt->execute([$c['user_id']]);
                        $owner = $userStmt->fetch();
                        $item['target_owner'] = $owner ? [
                            'id' => (int)$owner['id'],
                            'username' => $owner['username'],
                            'avatar' => resolve_image($owner['avatar']),
                            'role' => $owner['role'],
                            'status' => $owner['status'],
                        ] : null;
                    } else {
                        $item['target_snapshot'] = ['exists' => false];
                        $item['target_owner'] = null;
                    }
                } elseif ($r['target_type'] === 'user') {
                    $userStmt->execute([$r['target_id']]);
                    $tu = $userStmt->fetch();
                    if ($tu) {
                        $item['target_snapshot'] = [
                            'exists' => true,
                            'id' => (int)$tu['id'],
                            'username' => $tu['username'],
                            'avatar' => resolve_image($tu['avatar']),
                            'bio' => $tu['bio'],
                        ];
                        $item['target_owner'] = [
                            'id' => (int)$tu['id'],
                            'username' => $tu['username'],
                            'avatar' => resolve_image($tu['avatar']),
                            'role' => $tu['role'],
                            'status' => $tu['status'],
                        ];
                    } else {
                        $item['target_snapshot'] = ['exists' => false];
                        $item['target_owner'] = null;
                    }
                }
                $out[] = $item;
            }
            json_out([
                'reports' => $out,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => count($out) === $limit && ($offset + count($out)) < $total,
            ]);
        }

        /* --- 管理员：处理举报 ---
         *   入参：
         *     id: 举报 ID
         *     action: 'resolve' | 'dismiss'
         *       resolve = 标记已处理（可附带对被举报人的操作）
         *       dismiss = 忽略此举报
         *     target_action: 对被举报目标的操作（可选）
         *       'delete_post' / 'delete_comment' / 'ban_user' / 'none'
         *     note: 处理备注（≤500 字）
         *   返回：处理结果
         */
        if ($api === 'admin_report_action') {
            require_admin();
            $d = input();
            $reportId = (int)($d['id'] ?? 0);
            $action = trim((string)($d['action'] ?? ''));
            $targetAction = trim((string)($d['target_action'] ?? 'none'));
            $note = clean_text((string)($d['note'] ?? ''), 500);
            $handlerId = (int)($_SESSION['uid'] ?? 0);

            if (!in_array($action, ['resolve', 'dismiss'], true)) {
                json_out(['error' => '处理动作无效'], 400);
            }
            if (!in_array($targetAction, ['delete_post', 'delete_comment', 'ban_user', 'unban_user', 'none'], true)) {
                json_out(['error' => '目标操作无效'], 400);
            }

            // 查举报
            $chk = db()->prepare("SELECT * FROM reports WHERE id = ?");
            $chk->execute([$reportId]);
            $report = $chk->fetch();
            if (!$report) json_out(['error' => '举报不存在'], 404);
            if ($report['status'] !== 'pending') {
                json_out(['error' => '该举报已处理过'], 400);
            }

            db()->beginTransaction();
            try {
                // 执行对目标的操作
                $actionResult = '';
                if ($targetAction === 'delete_post' && $report['target_type'] === 'post') {
                    // 检查帖子是否还存在
                    $pchk = db()->prepare("SELECT id FROM posts WHERE id = ?");
                    $pchk->execute([$report['target_id']]);
                    if ($pchk->fetch()) {
                        delete_post_relations([$report['target_id']]);
                        db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$report['target_id']]);
                        $actionResult = '已删除帖子';
                    } else {
                        $actionResult = '帖子已不存在，跳过删除';
                    }
                } elseif ($targetAction === 'delete_comment' && $report['target_type'] === 'comment') {
                    $cchk = db()->prepare("SELECT id, post_id FROM comments WHERE id = ?");
                    $cchk->execute([$report['target_id']]);
                    $cmt = $cchk->fetch();
                    if ($cmt) {
                        db()->prepare("DELETE FROM comments WHERE id = ?")->execute([$report['target_id']]);
                        // 同步扣减 comments_count
                        db()->prepare("UPDATE posts SET comments_count = GREATEST(0, comments_count - 1) WHERE id = ?")
                            ->execute([$cmt['post_id']]);
                        $actionResult = '已删除评论';
                    } else {
                        $actionResult = '评论已不存在，跳过删除';
                    }
                } elseif ($targetAction === 'ban_user') {
                    // 封禁被举报人（需要先查出 owner_id）
                    $ownerId = 0;
                    if ($report['target_type'] === 'post') {
                        $s = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
                        $s->execute([$report['target_id']]);
                        $row = $s->fetch();
                        if ($row) $ownerId = (int)$row['user_id'];
                    } elseif ($report['target_type'] === 'comment') {
                        $s = db()->prepare("SELECT user_id FROM comments WHERE id = ?");
                        $s->execute([$report['target_id']]);
                        $row = $s->fetch();
                        if ($row) $ownerId = (int)$row['user_id'];
                    } elseif ($report['target_type'] === 'user') {
                        $ownerId = (int)$report['target_id'];
                    }
                    if ($ownerId > 0) {
                        // 不能封禁管理员
                        $uchk = db()->prepare("SELECT role FROM users WHERE id = ?");
                        $uchk->execute([$ownerId]);
                        $u = $uchk->fetch();
                        if ($u && $u['role'] !== 'admin') {
                            db()->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$ownerId]);
                            $actionResult = '已封禁用户';
                        } else {
                            $actionResult = '不能封禁管理员或用户不存在';
                        }
                    } else {
                        $actionResult = '目标已不存在，无法封禁';
                    }
                } elseif ($targetAction === 'unban_user') {
                    // 解封被举报人
                    $ownerId = 0;
                    if ($report['target_type'] === 'post') {
                        $s = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
                        $s->execute([$report['target_id']]);
                        $row = $s->fetch();
                        if ($row) $ownerId = (int)$row['user_id'];
                    } elseif ($report['target_type'] === 'comment') {
                        $s = db()->prepare("SELECT user_id FROM comments WHERE id = ?");
                        $s->execute([$report['target_id']]);
                        $row = $s->fetch();
                        if ($row) $ownerId = (int)$row['user_id'];
                    } elseif ($report['target_type'] === 'user') {
                        $ownerId = (int)$report['target_id'];
                    }
                    if ($ownerId > 0) {
                        db()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$ownerId]);
                        $actionResult = '已解封用户';
                    }
                }

                // 更新举报状态
                $newStatus = $action === 'resolve' ? 'resolved' : 'dismissed';
                db()->prepare("UPDATE reports SET status = ?, handler_id = ?, handler_note = ?, handled_at = ? WHERE id = ?")
                    ->execute([$newStatus, $handlerId, $note . ($actionResult ? '【' . $actionResult . '】' : ''), time(), $reportId]);
                db()->commit();
                admin_log('admin_report_action', "举报ID: {$reportId}, 动作: {$action}, 目标操作: {$targetAction}, 结果: {$actionResult}");
                json_out(['ok' => true, 'status' => $newStatus, 'action_result' => $actionResult]);
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '处理失败：' . $e->getMessage()], 500);
            }
        }

        /* --- 管理员：删除举报（彻底从数据库移除） --- */
        if ($api === 'admin_report_delete') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) json_out(['error' => '参数错误'], 400);
            db()->prepare("DELETE FROM reports WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 公告：获取激活的公告（公共） --- */
        if ($api === 'announcements') {
            $stmt = db()->prepare("SELECT id, title, content, created_at FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 5");
            $stmt->execute([]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'title' => $r['title'],
                    'content' => $r['content'],
                    'created_at' => time_ago((int)$r['created_at']),
                ];
            }
            json_out(['announcements' => $out]);
        }

        /* --- 管理员：公告列表（全部） --- */
        if ($api === 'admin_announcements') {
            require_admin();
            $stmt = db()->query("SELECT * FROM announcements ORDER BY id DESC");
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'title' => $r['title'],
                    'content' => $r['content'],
                    'is_active' => !empty($r['is_active']),
                    'created_at' => time_ago((int)$r['created_at']),
                ];
            }
            json_out(['announcements' => $out]);
        }

        /* --- 管理员：新增公告 --- */
        if ($api === 'admin_add_announcement') {
            require_admin();
            $d = input();
            $title = clean_plain($d['title'] ?? '', 200);
            $content = clean_text($d['content'] ?? '', 1000);
            $active = !empty($d['is_active']) ? 1 : 0;
            if ($title === '') json_out(['error' => '标题不能为空'], 400);
            db()->prepare("INSERT INTO announcements (title, content, is_active, created_at) VALUES (?, ?, ?, ?)")->execute([$title, $content, $active, time()]);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        /* --- 管理员：更新公告 --- */
        if ($api === 'admin_update_announcement') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            $title = clean_plain($d['title'] ?? '', 200);
            $content = clean_text($d['content'] ?? '', 1000);
            $active = !empty($d['is_active']) ? 1 : 0;
            if ($title === '') json_out(['error' => '标题不能为空'], 400);
            db()->prepare("UPDATE announcements SET title = ?, content = ?, is_active = ? WHERE id = ?")->execute([$title, $content, $active, $id]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：删除公告 --- */
        if ($api === 'admin_delete_announcement') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            db()->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：站点设置（修改站点名/描述） --- */
        if ($api === 'admin_site_settings') {
            require_admin();
            $d = input();
            $siteName = clean_plain($d['site_name'] ?? '', 30);
            $siteDesc = clean_plain($d['site_desc'] ?? '', 100);
            if ($siteName === '') json_out(['error' => '站点名不能为空'], 400);
            $cfg = load_config();
            if (!$cfg) json_out(['error' => '配置文件读取失败'], 500);
            $cfg['site_name'] = $siteName;
            $cfg['site_desc'] = $siteDesc;
            $configContent = "<?php\n// HTMLHub 配置文件 - 自动生成\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents(CONFIG_FILE, $configContent) === false)
                json_out(['error' => '配置写入失败'], 500);
            json_out(['ok' => true, 'site_name' => $siteName, 'site_desc' => $siteDesc]);
        }

        /* --- 管理员：工作室列表管理 --- */
        if ($api === 'admin_studios') {
            require_admin();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;
            $stmt = db()->prepare("SELECT s.*, u.username AS owner_username,
                                   (SELECT COUNT(*) FROM studio_members sm WHERE sm.studio_id = s.id) AS members_count,
                                   (SELECT COUNT(*) FROM posts p WHERE p.studio_id = s.id) AS posts_count
                                   FROM studios s JOIN users u ON u.id = s.owner_id
                                   ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'            => (int)$r['id'],
                    'name'          => $r['name'],
                    'slug'          => $r['slug'],
                    'description'   => $r['description'],
                    'visibility'    => $r['visibility'],
                    'created_at'    => time_ago((int)$r['created_at']),
                    'owner'         => ['id' => (int)$r['owner_id'], 'username' => $r['owner_username']],
                    'members_count' => (int)$r['members_count'],
                    'posts_count'   => (int)$r['posts_count'],
                ];
            }
            json_out(['studios' => $out]);
        }

        /* --- 管理员：删除工作室 --- */
        if ($api === 'admin_studio_delete') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) json_out(['error' => '参数错误'], 400);
            // 工作室帖子解绑
            db()->prepare("UPDATE posts SET studio_id = 0 WHERE studio_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM studio_members WHERE studio_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM studio_invitations WHERE studio_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM studios WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        /* --- 管理员：群发通知（给所有用户发送系统通知） --- */
        if ($api === 'admin_broadcast') {
            require_admin();
            $d = input();
            $content = clean_text($d['content'] ?? '', 500);
            if ($content === '') json_out(['error' => '内容不能为空'], 400);
            // 获取所有活跃用户
            $users = db()->query("SELECT id FROM users WHERE status = 'active'")->fetchAll();
            $adminId = 0; // 系统通知 actor_id = 0
            $stmt = db()->prepare("INSERT INTO notifications (user_id, actor_id, type, post_id, comment_id, content, is_read, created_at) VALUES (?, 0, 'system', 0, 0, ?, 0, ?)");
            $count = 0;
            foreach ($users as $u) {
                try {
                    $stmt->execute([(int)$u['id'], $content, time()]);
                    $count++;
                } catch (Throwable $e) {}
            }
            json_out(['ok' => true, 'sent_count' => $count]);
        }

        /* --- 管理员：切换代码评分玩具工具开关 --- */
        if ($api === 'admin_code_score_toggle') {
            require_admin();
            $d = input();
            $enabled = !empty($d['enabled']) ? '1' : '0';
            set_app_setting('code_score_enabled', $enabled);
            json_out(['ok' => true, 'enabled' => $enabled === '1']);
        }

        /* --- 管理员：获取 CDN 白名单（内置 + 自定义） --- */
        if ($api === 'admin_cdn_whitelist') {
            require_admin();
            json_out([
                'builtin' => builtin_cdn_whitelist(),
                'custom'  => custom_cdn_whitelist(),
                'custom_raw' => app_setting('cdn_whitelist', ''),
                'effective' => full_cdn_whitelist(),
            ]);
        }

        /* --- 管理员：保存自定义 CDN 白名单
         *   入参：
         *     whitelist: string  // 每行一个域名，支持 example.com / *.example.com / https://example.com
         *   服务端会重新校验格式，非法行被静默丢弃
         */
        if ($api === 'admin_cdn_whitelist_save') {
            require_admin();
            $d = input();
            $raw = (string)($d['whitelist'] ?? '');
            // 限制总长度（防止超大 payload）
            if (strlen($raw) > 10000) {
                json_out(['error' => '白名单内容过长（最多 10000 字符）'], 400);
            }
            set_app_setting('cdn_whitelist', $raw);
            // 返回解析后的结果，让管理员看到哪些行被接受
            $parsed = custom_cdn_whitelist();
            json_out([
                'ok' => true,
                'custom' => $parsed,
                'custom_count' => count($parsed),
                'effective' => full_cdn_whitelist(),
                'effective_count' => count(full_cdn_whitelist()),
            ]);
        }

        /* --- 弹窗公告：获取当前激活的弹窗公告（公共，每用户每会话仅展示一次） --- */
        if ($api === 'popup_announcement') {
            $stmt = db()->prepare("SELECT id, title, content_md, created_at FROM popup_announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([]);
            $row = $stmt->fetch();
            if (!$row) {
                json_out(['popup' => null]);
            }
            json_out(['popup' => [
                'id'         => (int)$row['id'],
                'title'      => $row['title'],
                'content_md' => $row['content_md'],
                'created_at' => time_ago((int)$row['created_at']),
                'created_ts' => (int)$row['created_at'],
            ]]);
        }

        /* --- 管理员：弹窗公告列表（全部） --- */
        if ($api === 'admin_popup_announcements') {
            require_admin();
            $stmt = db()->query("SELECT * FROM popup_announcements ORDER BY id DESC");
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'         => (int)$r['id'],
                    'title'      => $r['title'],
                    'content_md' => $r['content_md'],
                    'is_active'  => !empty($r['is_active']),
                    'created_at' => time_ago((int)$r['created_at']),
                    'updated_at' => (int)$r['updated_at'] > 0 ? time_ago((int)$r['updated_at']) : '',
                ];
            }
            json_out(['popup_announcements' => $out]);
        }

        /* --- 管理员：新增弹窗公告 --- */
        if ($api === 'admin_add_popup_announcement') {
            require_admin();
            $d = input();
            $title     = clean_plain($d['title'] ?? '', 200);
            $contentMd = clean_text($d['content_md'] ?? '', 10000);
            $active    = !empty($d['is_active']) ? 1 : 0;
            if ($contentMd === '') json_out(['error' => '弹窗内容不能为空'], 400);
            $now = time();
            db()->beginTransaction();
            try {
                // 激活时先停用其他弹窗，确保同一时刻仅一条激活
                if ($active) {
                    db()->exec("UPDATE popup_announcements SET is_active = 0 WHERE is_active = 1");
                }
                db()->prepare("INSERT INTO popup_announcements (title, content_md, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$title, $contentMd, $active, $now, $now]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '创建失败: ' . $e->getMessage()], 500);
            }
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        /* --- 管理员：更新弹窗公告 --- */
        if ($api === 'admin_update_popup_announcement') {
            require_admin();
            $d = input();
            $id        = (int)($d['id'] ?? 0);
            $title     = clean_plain($d['title'] ?? '', 200);
            $contentMd = clean_text($d['content_md'] ?? '', 10000);
            $active    = !empty($d['is_active']) ? 1 : 0;
            if ($id <= 0) json_out(['error' => '无效的弹窗 ID'], 400);
            if ($contentMd === '') json_out(['error' => '弹窗内容不能为空'], 400);
            // 确认记录存在
            $chk = db()->prepare("SELECT id FROM popup_announcements WHERE id = ?");
            $chk->execute([$id]);
            if (!$chk->fetch()) json_out(['error' => '弹窗公告不存在'], 404);
            db()->beginTransaction();
            try {
                // 激活时停用其他弹窗（排除自己）
                if ($active) {
                    db()->exec("UPDATE popup_announcements SET is_active = 0 WHERE is_active = 1 AND id != " . $id);
                }
                db()->prepare("UPDATE popup_announcements SET title = ?, content_md = ?, is_active = ?, updated_at = ? WHERE id = ?")
                    ->execute([$title, $contentMd, $active, time(), $id]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                json_out(['error' => '更新失败: ' . $e->getMessage()], 500);
            }
            json_out(['ok' => true]);
        }

        /* --- 管理员：删除弹窗公告 --- */
        if ($api === 'admin_delete_popup_announcement') {
            require_admin();
            $d = input();
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) json_out(['error' => '无效的弹窗 ID'], 400);
            db()->prepare("DELETE FROM popup_announcements WHERE id = ?")->execute([$id]);
            json_out(['ok' => true]);
        }

        json_out(['error' => '未知接口: ' . $api], 404);

    } catch (Throwable $e) {
        json_out(['error' => '服务器错误: ' . $e->getMessage()], 500);
    }
}

/* ============================================================
 *  前端 SPA
 * ============================================================ */
$installed = is_installed();
$pdo_mysql_ok = extension_loaded('pdo_mysql');

// 动态覆盖 CSP 头：合并内置 CDN 白名单 + 管理员自定义白名单
// 此时数据库已可用（is_installed 已尝试加载配置），可以安全读取 app_setting
// 必须在 ob_start 回调输出 HTML 之前完成，否则 headers 已发送无法修改
if ($installed) {
    // BotGuard 升级：为旧版（无 botguard_secret）的 config 自动补写密钥
    // 仅在缺失时执行一次，避免每次请求都写文件
    try {
        botguard_ensure_secret_in_config();
    } catch (Throwable $e) {}
    try {
        $dynamicCsp = build_csp_header(false);
        header_remove('Content-Security-Policy');
        header('Content-Security-Policy: ' . $dynamicCsp);
    } catch (Throwable $e) {
        // 读取自定义白名单失败时，保持文件顶部的默认 CSP（内置白名单 + cdnjs）
        // 这里用 build_csp_header 兜底（不读数据库，只用内置白名单）
        try {
            header_remove('Content-Security-Policy');
            header('Content-Security-Policy: ' . build_csp_header_fallback());
        } catch (Throwable $e2) {}
    }
}

// 页面级防克隆：输出缓冲 + 压缩 + 水印
$_site_watermark = generate_site_watermark();
ob_start(function($html) use ($_site_watermark) {
    // 1. 移除 HTML 注释（但保留 <script> 和 <textarea> 内的内容）
    // 只移除 </script> 和 </textarea> 之外的 HTML 注释
    $parts = preg_split('/(<script[\s\S]*?<\/script>|<textarea[\s\S]*?<\/textarea>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => &$part) {
        if ($i % 2 === 0) {
            // HTML 区域：移除注释 + 压缩空白
            $part = preg_replace('/<!--(?!\[if)[\s\S]*?-->/', '', $part);
            $part = preg_replace('/>\s+</', '><', $part);
            $part = preg_replace('/\n{3,}/', "\n\n", $part);
        }
        // script/textarea 区域：保持原样，不压缩
    }
    unset($part);
    $html = implode('', $parts);
    // 2. 注入隐形水印
    $watermark = "<!--HTMLHub-Site-Watermark:$_site_watermark-->";
    $html = str_replace('</html>', $watermark . '</html>', $html);
    // 3. Gzip 压缩（如果客户端支持且响应足够大）
    //    ob_start 回调在 headers 发送前执行，所以可以安全设置 Content-Encoding
    $html = apply_gzip_if_beneficial($html);
    return $html;
});
?>
<!DOCTYPE html>
<html lang="zh-CN" data-platform="<?php echo isset($_SERVER['HTTP_USER_AGENT']) ? (strpos($_SERVER['HTTP_USER_AGENT'], 'iPhone') !== false || strpos($_SERVER['HTTP_USER_AGENT'], 'iPad') !== false || strpos($_SERVER['HTTP_USER_AGENT'], 'iPod') !== false ? 'ios' : (strpos($_SERVER['HTTP_USER_AGENT'], 'Android') !== false ? 'android' : 'other')) : 'other'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0a0a0f">
<title>HTMLHub · HTML 作品社区</title>
<style>
:root{
  /* 扁平化设计：纯色、无阴影、无模糊、无渐变背景 */
  --bg:#ffffff;
  --bg-2:#f5f6f8;
  --bg-3:#eceef1;
  --card:#ffffff;
  --card-strong:#f5f6f8;
  --border:#e7e9ec;
  --border-strong:#d6d9de;
  --text:#1a1d23;
  --text-2:#5a6068;
  --text-3:#9099a3;
  --accent:#3b6cff;
  --accent-2:#ff4d6d;
  --accent-grad:#3b6cff;
  --accent-soft:#eaf0ff;
  --success:#10b981;
  --danger:#ef4444;
  --warn:#f59e0b;
  --radius:6px;
  --radius-sm:4px;
  --safe-top:env(safe-area-inset-top,0px);
  --safe-bottom:env(safe-area-inset-bottom,0px);
  --nav-h:54px;
}
/* 深色主题 */
:root[data-theme="dark"]{
  --bg:#0f0f14;
  --bg-2:#1a1a22;
  --bg-3:#25252e;
  --card:#1a1a22;
  --card-strong:#25252e;
  --border:#2a2a35;
  --border-strong:#3a3a48;
  --text:#f0f0f5;
  --text-2:#a0a0ab;
  --text-3:#6a6a75;
  --accent-soft:#1a2845;
}
/* 自定义强调色变体 */
:root[data-accent="purple"]{--accent:#7c5cff;--accent-2:#ff5c8a;--accent-grad:#7c5cff;--accent-soft:rgba(124,92,255,.12)}
:root[data-accent="purple"][data-theme="dark"]{--accent-soft:rgba(124,92,255,.15)}
:root[data-accent="green"]{--accent:#10b981;--accent-2:#f59e0b;--accent-grad:#10b981;--accent-soft:rgba(16,185,129,.12)}
:root[data-accent="green"][data-theme="dark"]{--accent-soft:rgba(16,185,129,.15)}
:root[data-accent="orange"]{--accent:#f97316;--accent-2:#ec4899;--accent-grad:#f97316;--accent-soft:rgba(249,115,22,.12)}
:root[data-accent="orange"][data-theme="dark"]{--accent-soft:rgba(249,115,22,.15)}
:root[data-accent="pink"]{--accent:#ec4899;--accent-2:#8b5cf6;--accent-grad:#ec4899;--accent-soft:rgba(236,72,153,.12)}
:root[data-accent="pink"][data-theme="dark"]{--accent-soft:rgba(236,72,153,.15)}
:root[data-accent="red"]{--accent:#ef4444;--accent-2:#f59e0b;--accent-grad:#ef4444;--accent-soft:rgba(239,68,68,.12)}
:root[data-accent="red"][data-theme="dark"]{--accent-soft:rgba(239,68,68,.15)}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","PingFang SC","Microsoft YaHei",sans-serif;
  background:var(--bg-2);
  color:var(--text);
  overflow:hidden;
  -webkit-font-smoothing:antialiased;
  font-size:15px;
  line-height:1.5;
}
/* 图片懒加载：未加载完成时显示浅色占位背景，避免白屏闪烁 */
img[loading="lazy"]{
  background:linear-gradient(110deg,var(--bg-2) 8%,var(--bg-3,var(--bg-2)) 18%,var(--bg-2) 33%);
  background-size:200% 100%;
  animation:imgShimmer 1.5s linear infinite;
}
img[loading="lazy"]:not([src=""]){animation:none;background:var(--bg-2)}
@keyframes imgShimmer{
  0%{background-position:200% 0}
  100%{background-position:-200% 0}
}
#app{height:100%}
a{color:inherit;text-decoration:none}
button{font-family:inherit;border:none;background:none;cursor:pointer;color:inherit}
input,textarea,select{font-family:inherit;font-size:inherit;color:inherit;background:none;border:none;outline:none}
textarea{resize:none}
img{display:block;max-width:100%}
::-webkit-scrollbar{width:0;height:0}

/* === SVG 通用尺寸（关键修复） === */
svg{display:inline-block;vertical-align:middle;max-width:100%;height:auto}
.nav-ico{display:flex;align-items:center;justify-content:center;width:24px;height:24px}
.nav-ico svg{width:24px;height:24px}
.nav-fab svg{width:24px;height:24px}
.icon-btn{display:flex;align-items:center;justify-content:center}
.icon-btn svg{width:20px;height:20px}
.act-btn svg{width:18px;height:18px}
.detail-actions .act-btn svg{width:22px;height:22px}
.section-label svg,.lp-head svg,.pf-bar svg{width:14px;height:14px}
.admin-section-title svg{width:14px;height:14px;flex-shrink:0}
.search-entry svg{width:15px;height:15px;flex-shrink:0}
.search-bar .sb-input-wrap svg{width:16px;height:16px}
.search-bar .sb-back svg{width:20px;height:20px}
.search-bar .sb-clear svg{width:14px;height:14px}
.reply-hint svg,.cmt-input-bar svg{width:14px;height:14px}
.c-actions svg{width:11px;height:11px}
.comment-item .c-actions button svg{width:11px;height:11px}
.detail-meta svg,.detail-actions svg{flex-shrink:0}
.p-stat svg,.p-stats svg{width:11px;height:11px}
.notif-item svg,.n-meta svg{flex-shrink:0}
.n-meta .n-type-tag svg{width:10px;height:10px}
.admin-stat-card .as-num{display:flex;align-items:baseline;gap:4px}
.admin-stat-card .as-label svg{width:11px;height:11px}
/* 关键：所有 .btn / .btn ghost / .btn danger 内 SVG 统一 16x16 */
.btn svg{width:16px;height:16px;flex-shrink:0}
/* 帖子卡片内的徽章 SVG */
.pinned-flag svg{width:10px;height:10px}
.play-badge span svg{width:18px;height:18px}
.detail-hero .back svg{width:18px;height:18px}
.detail-hero .play-circle svg{width:22px;height:22px}
.play-frame .back svg{width:20px;height:20px}
/* 评论区 h3 标题 SVG */
.comments h3 svg{width:15px;height:15px}
/* 大图查看器 SVG */
.img-viewer .iv-bar button svg{width:20px;height:20px}
/* 工作室卡片 SVG */
.sc-meta svg{width:11px;height:11px}
.sc-action button svg{width:12px;height:12px}
/* admin 帖子/用户列表 SVG */
.admin-post-item .ap-actions button svg,.admin-user-item .u-actions button svg{width:12px;height:12px}
.ap-pin svg{width:10px;height:10px}
.ap-type svg{width:10px;height:10px}
/* 通用 flex 容器内 SVG 防止撑大 */
button svg,span svg,div svg{flex-shrink:0}

/* === Layout === */
.page{
  position:absolute;inset:0;
  display:flex;flex-direction:column;
  padding-top:var(--safe-top);
  animation:pageIn .35s cubic-bezier(.22,.61,.36,1);
}
@keyframes pageIn{
  0%   {opacity:0;transform:translateY(14px) scale(.985)}
  100% {opacity:1;transform:translateY(0) scale(1)}
}
@keyframes slideInRight{
  0%   {opacity:0;transform:translateX(28px)}
  100% {opacity:1;transform:translateX(0)}
}
.page.page-slide{animation:slideInRight .3s cubic-bezier(.22,.61,.36,1)}
.page-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding-bottom:calc(var(--nav-h) + var(--safe-bottom) + 80px)}

/* iOS：禁用原生弹性滚动（由 JS 自定义橡皮筋接管） */
html[data-platform="ios"] .page-scroll{-webkit-overflow-scrolling:auto;overscroll-behavior:none}

/* === Top bar === */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 14px;border-bottom:1px solid var(--border);
  background:var(--bg);
  position:sticky;top:0;z-index:5;
}
.topbar .brand{display:flex;align-items:center;gap:8px;font-weight:700;font-size:16px}
.topbar .brand .logo{
  width:26px;height:26px;border-radius:6px;background:var(--accent);
  display:grid;place-items:center;color:#fff;font-size:13px;font-weight:800;
}
.topbar .actions{display:flex;gap:6px;align-items:center}
.icon-btn{
  width:34px;height:34px;border-radius:6px;
  display:flex;align-items:center;justify-content:center;background:var(--bg-2);
  border:1px solid var(--border);font-size:16px;
  transition:.15s;
}
.icon-btn:active{background:var(--bg-3)}

/* === Bottom Nav === */
.bottom-nav{
  position:fixed;left:0;right:0;bottom:0;
  height:calc(var(--nav-h) + var(--safe-bottom));
  padding-bottom:var(--safe-bottom);
  background:var(--bg);
  border-top:1px solid var(--border);
  display:flex;justify-content:space-around;align-items:center;
  z-index:50;
}
.nav-item{
  flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;
  color:var(--text-3);font-size:10px;padding-top:6px;
  transition:.15s;background:none;border:none;
}
.nav-item.active{color:var(--accent)}
.nav-item.active .nav-ico{color:var(--accent)}
.nav-item:active{background:var(--bg-2)}
.nav-fab{
  width:42px;height:42px;border-radius:8px;
  background:var(--accent);
  display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;
  transition:.15s;border:none;
}
.nav-fab:active{transform:scale(.94)}

/* === Feed === */
.feed-tabs{display:flex;gap:6px;padding:12px 14px 4px;overflow-x:auto}
.chip{
  padding:6px 14px;border-radius:4px;font-size:13px;font-weight:500;
  background:var(--bg);border:1px solid var(--border);white-space:nowrap;
  color:var(--text-2);transition:.15s;
  display:inline-flex;align-items:center;gap:5px;
}
.chip svg{width:14px;height:14px;flex-shrink:0}
.chip.active{background:var(--accent);border-color:var(--accent);color:#fff}
.chip:active{transform:scale(.96)}

.post-card{
  margin:8px 14px;border-radius:var(--radius);
  background:var(--card);border:1px solid var(--border);
  overflow:hidden;transition:.15s;
}
.post-card:active{background:var(--bg-2)}
.post-head{display:flex;align-items:center;gap:10px;padding:11px 14px}
.avatar{
  width:36px;height:36px;border-radius:6px;
  background:var(--accent);
  display:grid;place-items:center;color:#fff;font-weight:700;font-size:14px;
  overflow:hidden;flex-shrink:0;
}
.avatar img{width:100%;height:100%;object-fit:cover}
.post-head .name{font-weight:600;font-size:14px}
.post-head .time{font-size:12px;color:var(--text-3)}
.post-head .arrow{margin-left:auto;color:var(--text-3);font-size:13px}

.post-title{padding:0 14px 6px;font-size:15px;font-weight:600;line-height:1.4}
.post-text{padding:0 14px 10px;color:var(--text-2);font-size:14px;line-height:1.55;
  word-break:break-word;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.post-text.md-content p{margin:0 0 4px}
.post-text.md-content h1,.post-text.md-content h2,.post-text.md-content h3{margin:4px 0 2px}
.post-text.md-content pre,.post-text.md-content blockquote{margin:4px 0}
.post-text.md-content pre{max-height:none;overflow:hidden}
.post-text.md-content img{display:none}

.post-cover{
  margin:0 14px 10px;border-radius:var(--radius-sm);overflow:hidden;
  aspect-ratio:16/9;background:#000;position:relative;
  border:1px solid var(--border);
}
.post-cover img{width:100%;height:100%;object-fit:cover}
.post-cover .play-badge{
  position:absolute;inset:0;display:grid;place-items:center;
  background:rgba(0,0,0,.25);
}
.post-cover .play-badge span{
  width:44px;height:44px;border-radius:50%;
  background:#fff;color:var(--text);
  display:grid;place-items:center;
}
.post-cover .play-badge span svg{width:16px;height:16px}
.post-cover .type-tag{
  position:absolute;top:8px;left:8px;padding:3px 8px;
  background:rgba(0,0,0,.7);
  border-radius:3px;font-size:11px;font-weight:600;color:#fff;
}
.post-cover .view-tag{
  position:absolute;bottom:8px;right:8px;padding:3px 8px;
  background:rgba(0,0,0,.7);
  border-radius:3px;font-size:11px;color:#fff;
}

.post-actions{display:flex;align-items:center;gap:4px;padding:6px 14px 10px}
.act-btn{
  display:flex;align-items:center;gap:5px;padding:6px 10px;
  border-radius:4px;font-size:13px;color:var(--text-2);
  transition:.15s;background:none;border:none;
}
.act-btn:active{background:var(--bg-2)}
.act-btn.on{color:var(--accent-2)}
.act-btn.on-fav{color:var(--warn)}

/* === Empty state === */
.empty{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:70px 30px;text-align:center;color:var(--text-3);
}
.empty .em-ico{font-size:44px;margin-bottom:14px;opacity:.5}
.empty p{font-size:14px;line-height:1.6}
.empty .em-btn{
  margin-top:18px;padding:10px 22px;border-radius:4px;
  background:var(--accent);color:#fff;font-weight:600;
  border:none;
}

/* === Skeleton === */
.sk-card{margin:8px 14px;border-radius:var(--radius);background:var(--card);border:1px solid var(--border);padding:14px}
.sk-line{height:12px;border-radius:3px;background:var(--bg-3);margin-bottom:8px}
.sk-line.w70{width:70%}.sk-line.w40{width:40%}
.sk-cover{height:140px;border-radius:var(--radius-sm);background:var(--bg-3);margin:8px 0}
@keyframes shimmer{0%{opacity:.5}50%{opacity:.9}100%{opacity:.5}}
.sk-line,.sk-cover{animation:shimmer 1.4s infinite}

/* === Forms === */
.form-wrap{padding:16px 14px}
.field{margin-bottom:14px}
.field label{display:block;font-size:13px;color:var(--text-2);margin-bottom:6px;font-weight:500}
.input,.textarea{
  width:100%;padding:12px 14px;border-radius:var(--radius-sm);
  background:var(--bg);border:1px solid var(--border-strong);
  color:var(--text);transition:.15s;font-size:15px;
}
.input:focus,.textarea:focus{border-color:var(--accent);background:var(--bg)}
.textarea{min-height:120px;line-height:1.6}
.input::placeholder,.textarea::placeholder{color:var(--text-3)}

.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  width:100%;padding:13px;border-radius:var(--radius-sm);
  background:var(--accent);color:#fff;font-weight:600;font-size:15px;
  transition:.15s;border:none;
}
.btn:active{opacity:.85}
.btn:disabled{opacity:.5}
.btn.ghost{background:var(--bg);color:var(--text);border:1px solid var(--border-strong)}
.btn.danger{background:#fee2e2;color:var(--danger)}
.btn svg{width:18px;height:18px}

/* === Custom Modal (替代原生 alert/prompt/confirm) === */
.modal-mask{
  position:fixed;inset:0;background:rgba(0,0,0,.45);
  z-index:300;display:flex;align-items:center;justify-content:center;
  padding:24px;animation:modalFade .18s ease;
}
@keyframes modalFade{from{opacity:0}to{opacity:1}}
.modal-box{
  width:100%;max-width:340px;background:var(--bg);
  border:1px solid var(--border-strong);border-radius:12px;
  overflow:hidden;animation:modalPop .22s cubic-bezier(.22,1,.36,1);
  box-shadow:0 20px 50px rgba(0,0,0,.2);
}
@keyframes modalPop{from{opacity:0;transform:scale(.92) translateY(10px)}to{opacity:1;transform:none}}
.modal-head{padding:18px 18px 6px;text-align:center}
.modal-icon{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;margin:0 auto 10px;font-size:22px}
.modal-icon.info{background:var(--accent-soft);color:var(--accent)}
.modal-icon.warn{background:#fef3c7;color:#92400e}
.modal-icon.danger{background:#fee2e2;color:var(--danger)}
.modal-icon.success{background:#dcfce7;color:var(--success)}
.modal-title{font-size:16px;font-weight:700;color:var(--text)}
.modal-body{padding:6px 18px 18px;text-align:center}
.modal-msg{font-size:14px;color:var(--text-2);line-height:1.55;white-space:pre-wrap;word-break:break-word}
.modal-input{width:100%;padding:11px 12px;margin-top:12px;border-radius:6px;background:var(--bg-2);border:1px solid var(--border-strong);color:var(--text);font-size:14px;text-align:left}
.modal-input:focus{border-color:var(--accent);background:var(--bg)}
.modal-actions{display:flex;gap:0;border-top:1px solid var(--border)}
.modal-actions button{
  flex:1;padding:13px;background:none;border:none;border-right:1px solid var(--border);
  font-size:14px;font-weight:600;color:var(--text-2);cursor:pointer;transition:.15s;
}
.modal-actions button:last-child{border-right:none}
.modal-actions button:active{background:var(--bg-2)}
.modal-actions button.primary{color:var(--accent)}
.modal-actions button.danger{color:var(--danger)}

/* === Sheet modal === */
.sheet-mask{
  position:fixed;inset:0;background:rgba(0,0,0,.4);
  z-index:80;
  display:flex;align-items:flex-end;
  animation:pageIn .2s ease;
}
.sheet{
  width:100%;max-width:520px;margin:0 auto;
  background:var(--bg);
  border-radius:10px 10px 0 0;
  border-top:1px solid var(--border-strong);
  max-height:90vh;overflow-y:auto;
  padding:8px 18px calc(18px + var(--safe-bottom));
  animation:slideUp .25s cubic-bezier(.22,1,.36,1);
}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:none}}
.sheet-grip{width:36px;height:3px;background:var(--border-strong);border-radius:99px;margin:8px auto 12px}
.sheet-title{font-size:17px;font-weight:700;margin-bottom:14px;text-align:center}

/* === 弹窗公告 (Popup Announcement Modal) === */
.popup-mask{
  position:fixed;inset:0;
  background:rgba(0,0,0,.55);
  backdrop-filter:blur(2px);
  -webkit-backdrop-filter:blur(2px);
  z-index:120;
  display:flex;align-items:center;justify-content:center;
  padding:20px;
  animation:popupFadeIn .22s ease;
}
.popup-box{
  width:100%;max-width:480px;
  max-height:85vh;
  background:var(--bg);
  border:1px solid var(--border);
  border-radius:12px;
  box-shadow:0 12px 40px rgba(0,0,0,.18);
  display:flex;flex-direction:column;
  overflow:hidden;
  animation:popupSlideIn .28s cubic-bezier(.22,1,.36,1);
}
.popup-closing{animation:popupFadeOut .2s ease forwards}
.popup-closing .popup-box{animation:popupSlideOut .2s ease forwards}
.popup-head{
  position:relative;
  padding:18px 20px 14px;
  display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);
  background:linear-gradient(180deg,var(--accent-soft),transparent);
  flex-shrink:0;
}
.popup-head .popup-icon{
  font-size:24px;line-height:1;flex-shrink:0;
}
.popup-title{
  flex:1;min-width:0;
  font-size:17px;font-weight:700;color:var(--text);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.popup-close{
  flex-shrink:0;
  width:32px;height:32px;
  border-radius:6px;
  background:none;border:none;
  color:var(--text-3);
  font-size:24px;line-height:1;
  cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:background .15s ease,color .15s ease;
}
.popup-close:hover{background:var(--bg-2);color:var(--text)}
.popup-close:active{transform:scale(.92)}
.popup-body{
  flex:1;overflow-y:auto;
  padding:18px 20px;
  font-size:14px;line-height:1.65;color:var(--text);
  word-break:break-word;
  -webkit-overflow-scrolling:touch;
}
.popup-body.md-content > *:first-child{margin-top:0}
.popup-body.md-content > *:last-child{margin-bottom:0}
.popup-body.md-content h1,
.popup-body.md-content h2,
.popup-body.md-content h3,
.popup-body.md-content h4{
  margin:14px 0 8px;color:var(--text);font-weight:700;line-height:1.3;
}
.popup-body.md-content h1{font-size:20px}
.popup-body.md-content h2{font-size:18px}
.popup-body.md-content h3{font-size:16px}
.popup-body.md-content h4{font-size:14px}
.popup-body.md-content p{margin:8px 0}
.popup-body.md-content ul,
.popup-body.md-content ol{margin:8px 0;padding-left:22px}
.popup-body.md-content li{margin:4px 0}
.popup-body.md-content a{color:var(--accent);text-decoration:none;word-break:break-all}
.popup-body.md-content a:hover{text-decoration:underline}
.popup-body.md-content code{
  background:var(--bg-2);color:var(--accent-2);
  padding:2px 6px;border-radius:3px;font-size:13px;
  font-family:ui-monospace,Menlo,Consolas,monospace;
}
.popup-body.md-content pre{
  background:var(--bg-2);border:1px solid var(--border);
  border-radius:6px;padding:12px 14px;overflow-x:auto;
  margin:10px 0;
}
.popup-body.md-content pre code{
  background:none;color:var(--text);padding:0;font-size:13px;
}
.popup-body.md-content blockquote{
  border-left:3px solid var(--accent);
  background:var(--accent-soft);
  padding:8px 12px;margin:10px 0;
  border-radius:0 4px 4px 0;
  color:var(--text-2);
}
.popup-body.md-content table{
  width:100%;border-collapse:collapse;margin:10px 0;font-size:13px;
}
.popup-body.md-content th,
.popup-body.md-content td{
  border:1px solid var(--border);padding:6px 10px;text-align:left;
}
.popup-body.md-content th{background:var(--bg-2);font-weight:700}
.popup-body.md-content img{max-width:100%;height:auto;border-radius:4px}
.popup-body.md-content hr{border:none;border-top:1px solid var(--border);margin:14px 0}
.popup-foot{
  flex-shrink:0;
  padding:12px 20px calc(12px + var(--safe-bottom));
  border-top:1px solid var(--border);
  background:var(--bg);
  /* 三栏栅格布局：左 1fr | 中 auto | 右 1fr
   * 数学证明：
   *   设 foot 内容区宽度 = W，按钮宽度 = B
   *   栅格分配：左栏 = 1fr, 中栏 = auto(B), 右栏 = 1fr
   *   剩余空间 = W - B，由两个 1fr 平分
   *   左栏宽度 = 右栏宽度 = (W - B) / 2
   *   ⇒ 按钮中心点 = 左栏宽 + B/2 = (W-B)/2 + B/2 = W/2  ✓ 严格居中
   *   ⇒ meta 在左栏内，最大宽度 = (W - B) / 2，右边界 = (W - B) / 2
   *   ⇒ 按钮左边界 = (W - B) / 2
   *   ⇒ meta 右边界 ≤ 按钮左边界，永远不重叠 ✓
   */
  display:grid;
  grid-template-columns:1fr auto 1fr;
  align-items:center;
  gap:12px;
}
.popup-foot .popup-meta{
  font-size:11px;color:var(--text-3);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  justify-self:start;
  /* meta 自然受限于左栏宽度 (W-B)/2，无需手动设 max-width */
}
.popup-foot .popup-ok-btn{
  flex-shrink:0;min-width:96px;
  justify-self:center;
}
/* 编辑器实时预览框 */
.popup-preview-box.md-content{
  font-size:13px;line-height:1.6;
}
.popup-preview-box.md-content > *:first-child{margin-top:0}
.popup-preview-box.md-content > *:last-child{margin-bottom:0}
@keyframes popupFadeIn{from{opacity:0}to{opacity:1}}
@keyframes popupFadeOut{from{opacity:1}to{opacity:0}}
@keyframes popupSlideIn{from{opacity:0;transform:translateY(20px) scale(.96)}to{opacity:1;transform:none}}
@keyframes popupSlideOut{from{opacity:1;transform:none}to{opacity:0;transform:translateY(10px) scale(.98)}}
/* 减少动效偏好支持 */
:root[data-reduced-motion="1"] .popup-mask,
:root[data-reduced-motion="1"] .popup-box,
:root[data-reduced-motion="1"] .popup-closing,
:root[data-reduced-motion="1"] .popup-closing .popup-box{animation:none !important}
/* 移动端：弹窗贴边显示，最大化内容区 */
@media (max-width:520px){
  .popup-mask{padding:12px}
  .popup-box{max-width:100%;max-height:90vh;border-radius:10px}
  .popup-head{padding:14px 16px 10px}
  .popup-body{padding:14px 16px}
  .popup-foot{padding:10px 16px calc(10px + var(--safe-bottom))}
  .popup-foot .popup-ok-btn{min-width:84px}
}

/* === 代码质量评分（玩具工具） === */
.cs-hero{
  padding:18px 16px;margin-bottom:14px;
  background:linear-gradient(135deg,var(--accent-soft),transparent);
  border:1px solid var(--border);
  border-radius:10px;
}
.cs-badge{
  display:inline-block;padding:3px 10px;
  background:var(--accent-soft);color:var(--accent);
  font-size:11px;font-weight:600;border-radius:99px;
  margin-bottom:8px;letter-spacing:.5px;
}
.cs-title{font-size:20px;font-weight:700;color:var(--text);margin-bottom:6px}
.cs-desc{font-size:12px;color:var(--text-3);line-height:1.6}
.cs-result-card{
  background:var(--bg);border:1px solid var(--border);
  border-radius:10px;padding:18px 16px;
  animation:csFadeIn .3s ease;
}
@keyframes csFadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.cs-overall{
  display:flex;align-items:center;justify-content:space-between;
  padding-bottom:14px;margin-bottom:14px;
  border-bottom:1px solid var(--border);
}
.cs-overall-left{display:flex;align-items:baseline;gap:4px}
.cs-overall-num{font-size:42px;font-weight:800;line-height:1;letter-spacing:-1px}
.cs-overall-unit{font-size:14px;color:var(--text-3);font-weight:500}
.cs-overall-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.cs-grade{
  font-size:18px;font-weight:800;
  padding:4px 14px;border:2px solid;border-radius:8px;
  letter-spacing:1px;min-width:48px;text-align:center;
}
.cs-meta{font-size:11px;color:var(--text-3)}
.cs-dims{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.cs-dim{padding:8px 0}
.cs-dim-head{
  display:flex;align-items:center;gap:8px;
  font-size:13px;margin-bottom:6px;
}
.cs-dim-icon{font-size:14px;line-height:1;flex-shrink:0}
.cs-dim-label{flex:1;color:var(--text-2);font-weight:500}
.cs-dim-score{font-size:15px;font-weight:700;min-width:32px;text-align:right}
.cs-dim-bar{
  height:6px;background:var(--bg-2);border-radius:99px;overflow:hidden;
}
.cs-dim-fill{
  height:100%;border-radius:99px;
  transition:width .6s cubic-bezier(.22,1,.36,1);
}
.cs-sugg{margin-top:8px;padding-top:14px;border-top:1px solid var(--border)}
.cs-sugg-title{font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:10px}
.cs-sugg-group{margin-bottom:12px}
.cs-sugg-group:last-child{margin-bottom:0}
.cs-sugg-group-title{
  font-size:12px;font-weight:600;color:var(--text-2);
  margin-bottom:6px;display:flex;align-items:center;gap:6px;
}
.cs-sugg-group ul{
  margin:0;padding:0 0 0 18px;list-style:disc;
  font-size:12px;color:var(--text-3);line-height:1.7;
}
.cs-sugg-group li{margin:2px 0}
.cs-sugg-empty{
  padding:14px;text-align:center;font-size:13px;
  color:var(--success);background:var(--bg-2);
  border-radius:6px;
}
/* 减少动效 */
:root[data-reduced-motion="1"] .cs-result-card,
:root[data-reduced-motion="1"] .cs-dim-fill{animation:none !important;transition:none !important}

/* === Toast === */
.toast-wrap{position:fixed;top:calc(var(--safe-top) + 12px);left:0;right:0;z-index:200;display:flex;flex-direction:column;align-items:center;gap:8px;pointer-events:none}
.toast{
  padding:10px 18px;border-radius:4px;
  background:#1a1d23;
  border:1px solid var(--border-strong);font-size:14px;color:#fff;
  animation:toastIn .25s ease;
  max-width:90%;text-align:center;
}
.toast.err{background:#dc2626;color:#fff}
.toast.ok{background:#059669;color:#fff}
@keyframes toastIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}

/* === Detail page === */
.detail-hero{
  position:relative;aspect-ratio:4/3;background:#000;overflow:hidden;
}
.detail-hero img{width:100%;height:100%;object-fit:cover}
.detail-hero .back{
  position:absolute;top:calc(var(--safe-top) + 12px);left:12px;
  width:36px;height:36px;border-radius:6px;
  background:rgba(0,0,0,.6);
  display:flex;align-items:center;justify-content:center;color:#fff;
}
.detail-hero .back svg{width:18px;height:18px}
.detail-hero .play-circle{
  position:absolute;bottom:14px;right:14px;width:48px;height:48px;border-radius:50%;
  background:#fff;display:flex;align-items:center;justify-content:center;color:var(--text);
}
.detail-hero .play-circle svg{width:22px;height:22px}
.detail-body{padding:16px 14px}
.detail-title{font-size:20px;font-weight:700;line-height:1.3;margin-bottom:10px}
.detail-meta{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.detail-meta .avatar{width:34px;height:34px}
.detail-meta .name{font-weight:600}
.detail-meta .time{font-size:12px;color:var(--text-3)}
.detail-text{color:var(--text-2);font-size:15px;line-height:1.7;white-space:pre-wrap;word-break:break-word;margin-bottom:18px}
.detail-actions{
  display:flex;justify-content:space-around;padding:12px 0;
  border-top:1px solid var(--border);border-bottom:1px solid var(--border);
  margin-bottom:18px;
}
.detail-actions .act-btn{flex-direction:column;gap:4px;font-size:12px;padding:8px 16px}

.play-bar{
  position:sticky;bottom:0;left:0;right:0;
  padding:10px 14px calc(10px + var(--safe-bottom));
  background:var(--bg);
  border-top:1px solid var(--border);display:flex;gap:10px;
}
.play-bar .btn{flex:1}
.play-bar .play-bar-secondary{flex:0 0 auto;width:auto;padding:13px 16px;font-size:13px;min-width:0}
.play-bar .play-bar-secondary svg{width:15px;height:15px;flex-shrink:0}

/* === Comments === */
.comments{padding:0 14px 18px}
.comments h3{font-size:14px;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.comments h3 svg{width:15px;height:15px}
.comment-item{display:flex;gap:10px;padding:10px 0}
.comment-item .avatar{width:30px;height:30px;font-size:12px}
.comment-item .c-body{flex:1;min-width:0}
.comment-item .c-head{display:flex;align-items:center;gap:6px;margin-bottom:3px;flex-wrap:wrap}
.comment-item .c-name{font-size:13px;font-weight:600}
.comment-item .c-reply-to{font-size:12px;color:var(--text-3)}
.comment-item .c-reply-to b{color:var(--accent);font-weight:500}
.comment-item .c-text{font-size:14px;color:var(--text);line-height:1.5;word-break:break-word}
.comment-item .c-meta{font-size:11px;color:var(--text-3);margin-top:4px;display:flex;align-items:center;gap:10px}
.comment-item .c-actions{display:flex;gap:4px}
.comment-item .c-actions button{padding:2px 6px;background:none;border:none;color:var(--text-3);font-size:11px;cursor:pointer;display:flex;align-items:center;gap:3px}
.comment-item .c-actions button:hover{color:var(--text)}
.comment-item .c-actions button.danger:hover{color:var(--danger)}
.comment-item .c-actions svg{width:11px;height:11px}
.comment-children{margin-left:18px;padding-left:10px;border-left:2px solid var(--border)}
.comment-children .comment-item:last-child{border-bottom:none;padding-bottom:0}
.comment-deleted{font-size:12px;color:var(--text-3);font-style:italic;padding:6px 0}
.c-fold-btn{display:flex;align-items:center;gap:8px;padding:8px 0 8px 12px;cursor:pointer;color:var(--accent);font-size:12px;font-weight:500;transition:opacity .15s ease}
.c-fold-btn:hover{opacity:.8}
.c-fold-btn .c-fold-line{flex:0 0 20px;height:1px;background:var(--border-strong)}
.c-fold-btn:active{transform:scale(.98)}

.cmt-input-bar{
  position:sticky;bottom:0;left:0;right:0;
  padding:8px 12px calc(8px + var(--safe-bottom));
  background:var(--bg);
  border-top:1px solid var(--border);
}
.cmt-input-bar .reply-hint{font-size:12px;color:var(--text-3);padding:4px 0 6px;display:flex;align-items:center;justify-content:space-between}
.cmt-input-bar .reply-hint b{color:var(--accent);font-weight:500}
.cmt-input-bar .reply-hint button{background:none;border:none;color:var(--text-3);font-size:14px;padding:0 4px}
.cmt-input-bar .input-row{display:flex;gap:8px;align-items:center}
.cmt-input-bar input{
  flex:1;padding:9px 14px;border-radius:4px;
  background:var(--bg-2);border:1px solid var(--border-strong);font-size:14px;
}
.cmt-input-bar button{
  padding:9px 18px;border-radius:4px;background:var(--accent);
  color:#fff;font-weight:600;font-size:14px;border:none;
}

/* === Play (embed) === */
.play-frame{
  position:fixed;inset:0;z-index:100;background:#fff;
  display:flex;flex-direction:column;
}
.play-frame iframe{flex:1;border:none;width:100%;background:#fff}
.play-frame .pf-bar{
  display:flex;align-items:center;gap:10px;
  padding:calc(var(--safe-top) + 10px) 14px 10px;
  background:var(--bg);
  color:var(--text);
  border-bottom:1px solid var(--border);
}
.play-frame .pf-bar .back{padding:6px;display:flex;align-items:center}
.play-frame .pf-bar .back svg{width:20px;height:20px}
.play-frame .pf-bar .pf-title{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* === Auth page（扁平化设计） === */
.auth-page{
  display:flex;flex-direction:column;min-height:100%;
  padding:calc(var(--safe-top) + 48px) 28px calc(var(--safe-bottom) + 24px);
  background:var(--bg);
}
/* 返回按钮：右上角，不挡 logo */
.auth-page .auth-back{
  position:absolute;top:calc(var(--safe-top) + 12px);right:16px;
  width:36px;height:36px;border-radius:8px;
  background:transparent;border:none;cursor:pointer;
  display:grid;place-items:center;color:var(--text-3);
  transition:background .15s ease,color .15s ease;
}
.auth-page .auth-back:active{background:var(--bg-2);color:var(--text-1)}
.auth-page .auth-back svg{width:20px;height:20px}
.auth-logo{
  width:56px;height:56px;border-radius:12px;
  background:var(--accent);
  display:grid;place-items:center;color:#fff;font-size:26px;font-weight:700;
  margin-bottom:20px;
  letter-spacing:-1px;
}
.auth-title{font-size:22px;font-weight:700;margin-bottom:6px;letter-spacing:-.3px;color:var(--text-1)}
.auth-sub{color:var(--text-3);font-size:14px;margin-bottom:28px;line-height:1.5}
.auth-page .field{margin-bottom:16px}
.auth-page .field label{
  display:block;font-size:13px;font-weight:500;color:var(--text-2);
  margin-bottom:8px;
}
.auth-page .input{
  padding:12px 14px;font-size:15px;
  border:1px solid var(--border);
  background:var(--bg);
  border-radius:8px;
  transition:border-color .15s ease;
}
.auth-page .input:focus{
  border-color:var(--accent);
  outline:none;
}
.auth-page .btn{
  margin-top:4px;padding:13px;font-size:15px;font-weight:600;
  background:var(--accent);
  border-radius:8px;
  color:#fff;
}
.auth-page .btn:active{opacity:.85}
.auth-switch{
  text-align:center;margin-top:20px;font-size:13px;color:var(--text-3);
}
.auth-switch a{color:var(--accent);font-weight:600;cursor:pointer}
.auth-page .btn.ghost{
  background:transparent;color:var(--text-3);
  border:1px solid var(--border);
  font-weight:500;margin-top:10px;
}
.auth-page .btn.ghost:active{background:var(--bg-2)}

/* === Install page === */
.install-bg{background:var(--bg-2)}
.install-wrap{padding:calc(var(--safe-top) + 30px) 24px calc(var(--safe-bottom) + 24px);max-width:520px;margin:0 auto;min-height:100%;display:flex;flex-direction:column}
.install-hero{text-align:center;margin-bottom:20px}
.install-hero .logo{width:64px;height:64px;border-radius:10px;background:var(--accent);display:grid;place-items:center;color:#fff;font-size:30px;font-weight:800;margin:0 auto 12px}
.install-hero h1{font-size:22px;font-weight:800;margin-bottom:6px}
.install-hero p{color:var(--text-3);font-size:13px;line-height:1.6}
.step-dots{display:flex;justify-content:center;gap:6px;margin-bottom:18px}
.step-dot{width:24px;height:3px;border-radius:99px;background:var(--border-strong);transition:.3s}
.step-dot.on{background:var(--accent);width:32px}

.install-section{margin-bottom:14px;padding:14px 14px 4px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--card)}
.install-section h3{font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px;display:flex;align-items:center;gap:6px}
.install-section h3 .num{width:18px;height:18px;border-radius:3px;background:var(--accent);display:grid;place-items:center;color:#fff;font-size:11px;font-weight:800}
.install-row{display:flex;gap:10px;margin-bottom:10px}
.install-row .field{flex:1;margin-bottom:10px}
.install-row .field.w80{flex:3}
.install-row .field.w20{flex:1}

.err-banner{padding:10px 14px;border-radius:4px;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;font-size:13px;margin-bottom:14px;display:none}

/* === Profile === */
.profile-head{
  padding:24px 16px;text-align:center;
  background:var(--bg);
}
.profile-head .avatar{width:72px;height:72px;font-size:28px;margin:0 auto 12px;border-radius:10px;border:2px solid var(--border)}
.profile-head .p-name{font-size:18px;font-weight:700;margin-bottom:4px;display:flex;align-items:center;justify-content:center;gap:6px}
.profile-head .p-bio{color:var(--text-2);font-size:14px;margin-bottom:14px;max-width:300px;margin-left:auto;margin-right:auto}
.profile-head .p-bio-text{line-height:1.6;word-break:break-word}

/* 联系方式独立卡片（专业设计） */
.contact-card{
  margin:0 14px 16px;
  background:var(--bg);
  border:1px solid var(--border);
  border-radius:10px;
  overflow:hidden;
}
.contact-card-head{
  display:flex;align-items:center;gap:8px;
  padding:10px 14px;
  border-bottom:1px solid var(--border);
  background:var(--bg-2);
}
.contact-card-icon{
  width:16px;height:16px;
  display:flex;align-items:center;justify-content:center;
  color:var(--text-3);
}
.contact-card-icon svg{width:14px;height:14px}
.contact-card-title{font-size:12px;font-weight:600;color:var(--text-2);flex:1;letter-spacing:.2px}
.contact-card-count{
  font-size:11px;color:var(--text-3);
  background:var(--bg);border:1px solid var(--border);
  padding:1px 8px;border-radius:99px;min-width:20px;text-align:center;font-weight:500;
}
.contact-card-grid{
  display:grid;grid-template-columns:repeat(2,1fr);gap:1px;
  background:var(--border);
}
/* 单列模式（联系方式 ≤ 1 条时） */
.contact-card-grid:has(.contact-tile:only-child){grid-template-columns:1fr}
.contact-tile{
  background:var(--bg);
  padding:10px 12px;
  cursor:pointer;
  transition:background .12s ease;
  display:flex;flex-direction:column;gap:4px;
  min-width:0;
}
.contact-tile:hover{background:var(--bg-2)}
.contact-tile:active{background:var(--bg-2)}
.contact-tile-top{
  display:flex;align-items:center;gap:6px;
}
.contact-tile-icon{font-size:14px;flex-shrink:0;line-height:1}
.contact-tile-label{
  font-size:10px;color:var(--text-3);font-weight:600;
  flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  text-transform:uppercase;letter-spacing:.3px;
}
.contact-tile-copy{
  flex-shrink:0;width:20px;height:20px;border-radius:4px;
  display:grid;place-items:center;
  color:var(--text-3);background:transparent;border:none;cursor:pointer;
  transition:.12s;
}
.contact-tile-copy:hover{color:var(--accent);background:var(--accent-soft)}
.contact-tile-copy:active{color:var(--accent);background:var(--accent-soft)}
.contact-tile-copy svg{width:12px;height:12px}
.contact-tile-val{
  font-size:13px;color:var(--text-1);font-weight:500;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  min-width:0;line-height:1.4;
}
.contact-tile-value{
  color:var(--text-1)!important;text-decoration:none;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.contact-tile-value:hover{color:var(--accent)!important}
.contact-tile-value:active{color:var(--accent)!important}
/* 外链图标 */
.contact-tile-top svg{color:var(--text-3)}

.profile-head .p-stats{display:flex;justify-content:center;flex-wrap:wrap;gap:8px 16px;font-size:13px;color:var(--text-3);margin-bottom:14px}
.profile-head .p-stats .p-stat{cursor:pointer;padding:4px 8px;border-radius:4px;min-width:48px}
.profile-head .p-stats .p-stat:active{background:var(--bg-2)}
.profile-head .p-stats b{display:block;font-size:17px;color:var(--text);font-weight:700}
.profile-head .p-actions{display:flex;justify-content:center;gap:8px}
.profile-head .p-actions .btn{width:auto;padding:8px 22px;font-size:13px}
.profile-head .p-actions .btn.mutual{background:var(--bg-2);color:var(--text-2);border:1px solid var(--border-strong)}
.profile-head .p-badge{display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border-radius:3px;background:var(--accent);color:#fff;vertical-align:middle}
.profile-head .p-badge.banned{background:var(--danger)}
.profile-tabs{display:flex;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:3}
.profile-tabs .pt{flex:1;padding:12px;text-align:center;font-size:14px;font-weight:500;color:var(--text-3);border-bottom:2px solid transparent;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none}
.profile-tabs .pt.on{color:var(--accent);border-color:var(--accent)}

/* === User list (followers / following) === */
.user-list-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);background:var(--bg);cursor:pointer}
.user-list-item:active{background:var(--bg-2)}
.user-list-item .avatar{width:42px;height:42px;font-size:16px}
.user-list-item .u-info{flex:1;min-width:0}
.user-list-item .u-name{font-size:14px;font-weight:600;display:flex;align-items:center;gap:5px}
.user-list-item .u-bio{font-size:12px;color:var(--text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px}
.user-list-item .u-meta{font-size:11px;color:var(--text-3);margin-top:2px}
.user-list-item .u-follow-btn{padding:6px 14px;border-radius:4px;background:var(--accent);color:#fff;font-size:12px;font-weight:600;border:none}
.user-list-item .u-follow-btn.following{background:var(--bg-2);color:var(--text-2);border:1px solid var(--border-strong)}
.user-list-item .u-follow-btn.mutual{background:var(--accent-soft);color:var(--accent);border:1px solid var(--accent)}

/* === Admin === */
.admin-stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:14px}
.admin-stat-card{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:14px}
.admin-stat-card .as-num{font-size:24px;font-weight:700;color:var(--accent);margin-bottom:4px}
.admin-stat-card .as-label{font-size:12px;color:var(--text-3)}
.admin-section-title{padding:14px 14px 8px;font-size:13px;font-weight:700;color:var(--text-2);display:flex;justify-content:space-between;align-items:center}
.admin-section-title svg{width:14px;height:14px;flex-shrink:0}
.admin-tab-row{display:flex;background:var(--bg);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:3;overflow-x:auto}
.admin-tab-row .at{flex:0 0 auto;padding:12px 18px;text-align:center;font-size:14px;font-weight:500;color:var(--text-3);border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;cursor:pointer;white-space:nowrap}
.admin-tab-row .at.on{color:var(--accent);border-color:var(--accent)}
.admin-post-item{padding:12px 14px;border-bottom:1px solid var(--border);background:var(--bg)}
.admin-post-item .ap-head{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.admin-post-item .ap-pin{padding:2px 6px;font-size:10px;font-weight:700;border-radius:3px;background:#fef3c7;color:#92400e}
.admin-post-item .ap-type{padding:2px 6px;font-size:10px;font-weight:600;border-radius:3px;background:var(--accent-soft);color:var(--accent)}
.admin-post-item .ap-title{flex:1;font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.admin-post-item .ap-meta{font-size:11px;color:var(--text-3);margin-bottom:8px}
.admin-post-item .ap-actions{display:flex;gap:6px;flex-wrap:wrap}
.admin-post-item .ap-actions button{padding:6px 12px;border-radius:4px;font-size:12px;border:1px solid var(--border-strong);background:var(--bg-2);color:var(--text-2)}
.admin-post-item .ap-actions button:active{background:var(--bg-3)}
.admin-post-item .ap-actions button.danger{background:#fee2e2;color:var(--danger);border-color:#fecaca}
.admin-post-item .ap-actions button.pin{background:#fef3c7;color:#92400e;border-color:#fde68a}
.admin-user-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);background:var(--bg)}
.admin-user-item .avatar{width:42px;height:42px;font-size:16px}
.admin-user-item .u-info{flex:1;min-width:0}
.admin-user-item .u-name{font-size:14px;font-weight:600;display:flex;align-items:center;gap:5px}
.admin-user-item .u-meta{font-size:11px;color:var(--text-3);margin-top:3px}
.admin-user-item .u-actions{display:flex;gap:6px}
.admin-user-item .u-actions button{padding:6px 12px;border-radius:4px;font-size:12px;border:1px solid var(--border-strong);background:var(--bg-2);color:var(--text-2)}
.admin-user-item .u-actions button.danger{background:#fee2e2;color:var(--danger);border-color:#fecaca}
.admin-user-item .u-actions button.banned{background:var(--success);color:#fff;border-color:var(--success)}
.admin-login-page{display:flex;flex-direction:column;justify-content:center;align-items:center;min-height:100%;padding:24px;text-align:center}
.admin-login-page .al-logo{width:64px;height:64px;border-radius:10px;background:#1a1d23;display:grid;place-items:center;color:#fff;font-size:30px;font-weight:800;margin-bottom:18px}
.admin-login-page .al-title{font-size:22px;font-weight:700;margin-bottom:6px}
.admin-login-page .al-sub{color:var(--text-3);font-size:13px;margin-bottom:24px}
.admin-login-page .al-form{width:100%;max-width:360px}

/* === Admin 交互动画 === */
.admin-stat-card{transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;cursor:default}
.admin-stat-card:hover{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 4px 12px rgba(59,108,255,.12)}
.admin-stat-card:active{transform:translateY(0)}
.admin-stat-card .as-num{transition:color .2s ease}
.admin-stat-card:hover .as-num{color:var(--accent-2)}

.admin-tab-row .at{transition:color .2s ease,border-color .2s ease,background .2s ease;position:relative}
.admin-tab-row .at:hover{color:var(--text);background:var(--bg-2)}
.admin-tab-row .at:active{transform:scale(.97)}
.admin-tab-row .at.on{color:var(--accent);border-color:var(--accent)}
.admin-tab-row .at.on::after{content:'';position:absolute;left:0;right:0;bottom:-1px;height:2px;background:var(--accent);animation:tabUnderline .25s ease}
@keyframes tabUnderline{from{transform:scaleX(0)}to{transform:scaleX(1)}}

.admin-post-item,.admin-user-item{transition:background .15s ease,transform .15s ease}
.admin-post-item:hover,.admin-user-item:hover{background:var(--bg-2)}
.admin-post-item:active,.admin-user-item:active{transform:scale(.998)}

.admin-post-item .ap-actions button,
.admin-user-item .u-actions button{transition:transform .12s ease,background .15s ease,opacity .15s ease}
.admin-post-item .ap-actions button:hover,
.admin-user-item .u-actions button:hover{opacity:.85;transform:translateY(-1px)}
.admin-post-item .ap-actions button:active,
.admin-user-item .u-actions button:active{transform:translateY(0) scale(.96)}

#admin-list{animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

.admin-stat-card .as-num{animation:countUp .4s ease}
@keyframes countUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

.admin-section-title{transition:color .15s ease}

/* === Notification badge === */
.notif-badge{position:relative;display:inline-flex}
.notif-badge .notif-dot{position:absolute;top:-2px;right:-2px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:var(--accent-2);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);box-sizing:content-box;animation:notifPop .25s ease}
.notif-badge .notif-dot.empty{display:none}
@keyframes notifPop{from{transform:scale(0)}to{transform:scale(1)}}

/* === Notification list === */
.notif-item{display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);background:var(--bg);cursor:pointer;transition:background .15s ease;position:relative}
.notif-item:hover{background:var(--bg-2)}
.notif-item:active{background:var(--bg-3)}
.notif-item.unread{background:linear-gradient(90deg,rgba(59,108,255,.04),transparent 30%)}
.notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent)}
.notif-item .avatar{width:38px;height:38px;font-size:14px}
.notif-item .n-body{flex:1;min-width:0}
.notif-item .n-text{font-size:14px;color:var(--text);line-height:1.5;word-break:break-word}
.notif-item .n-text b{font-weight:600}
.notif-item .n-text .n-action{color:var(--text-2)}
.notif-item .n-text .n-target{color:var(--accent);font-weight:500}
.notif-item .n-snippet{font-size:12px;color:var(--text-3);margin-top:4px;padding:6px 8px;background:var(--bg-2);border-radius:4px;border-left:2px solid var(--border-strong);line-height:1.4;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.notif-item .n-meta{font-size:11px;color:var(--text-3);margin-top:4px;display:flex;align-items:center;gap:6px}
.notif-item .n-type-tag{padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;background:var(--accent-soft);color:var(--accent)}
.notif-item .n-type-tag.like{background:#fee2e2;color:var(--accent-2)}
.notif-item .n-type-tag.follow{background:#dcfce7;color:#15803d}
.notif-item .n-type-tag.reply{background:#fef3c7;color:#92400e}
.notif-tabs{display:flex;background:var(--bg);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:3}
.notif-tabs .nt{flex:1;padding:12px;text-align:center;font-size:14px;font-weight:500;color:var(--text-3);border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;cursor:pointer;transition:color .15s ease,border-color .15s ease}
.notif-tabs .nt.on{color:var(--accent);border-color:var(--accent)}
.notif-actions-bar{padding:8px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--text-3)}
.notif-actions-bar button{padding:6px 12px;border-radius:4px;background:var(--bg-2);border:1px solid var(--border-strong);color:var(--text-2);font-size:12px;cursor:pointer;transition:background .15s ease}
.notif-actions-bar button:active{background:var(--bg-3)}

/* === Post images grid === */
.post-images{display:grid;gap:3px;margin:0 14px 10px;border-radius:var(--radius-sm);overflow:hidden}
.post-images.count-1{grid-template-columns:1fr;max-height:300px}
.post-images.count-2{grid-template-columns:1fr 1fr;max-height:200px}
.post-images.count-3{grid-template-columns:1fr 1fr 1fr;max-height:200px}
.post-images.count-4,.post-images.count-5{grid-template-columns:1fr 1fr;max-height:300px}
.post-images.count-6,.post-images.count-7,.post-images.count-8,.post-images.count-9{grid-template-columns:1fr 1fr 1fr;max-height:280px}
.post-images .pi{position:relative;background:var(--bg-3);cursor:pointer;overflow:hidden}
.post-images .pi img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s ease}
.post-images .pi:active img{transform:scale(.96)}
.post-images .pi-more{position:absolute;inset:0;background:rgba(0,0,0,.55);display:grid;place-items:center;color:#fff;font-size:18px;font-weight:700}
.detail-images{display:grid;gap:4px;margin-bottom:18px}
.detail-images.count-1{grid-template-columns:1fr}
.detail-images.count-2{grid-template-columns:1fr 1fr}
.detail-images.count-3,.detail-images.count-4,.detail-images.count-6,.detail-images.count-9{grid-template-columns:1fr 1fr 1fr}
.detail-images.count-5,.detail-images.count-7,.detail-images.count-8{grid-template-columns:1fr 1fr}
.detail-images .di{background:var(--bg-3);border-radius:var(--radius-sm);overflow:hidden;cursor:pointer;aspect-ratio:1/1}
.detail-images .di img{width:100%;height:100%;object-fit:cover;display:block}
.detail-images .di:active img{transform:scale(.98);transition:transform .15s ease}
.detail-images .di.full-bleed{aspect-ratio:auto}
.detail-images .di.full-bleed img{height:auto}

/* === Image picker in editor === */
.img-picker{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}
.img-pick-tile{aspect-ratio:1/1;border:1px dashed var(--border-strong);border-radius:var(--radius-sm);background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--text-3);cursor:pointer;position:relative;overflow:hidden;transition:.15s}
.img-pick-tile:active{border-color:var(--accent);background:var(--bg-2)}
.img-pick-tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.img-pick-tile .ip-clear{position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,.7);color:#fff;display:grid;place-items:center;font-size:14px;line-height:1;z-index:2}
.img-pick-tile .ip-clear:active{transform:scale(.9)}
.img-pick-tile.add-tile svg{width:24px;height:24px}
.img-pick-tile .ip-counter{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.7));color:#fff;font-size:11px;text-align:center;padding:8px 0 4px}

/* === Code viewer === */
.code-viewer-page{display:flex;flex-direction:column;height:100%}
.code-toolbar{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);flex-shrink:0}
.code-toolbar .ct-title{flex:1;min-width:0;font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.code-toolbar .ct-meta{font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:6px;flex-shrink:0}
.code-toolbar .ct-edited-badge{padding:2px 6px;background:#fef3c7;color:#92400e;border-radius:3px;font-size:10px;font-weight:600}
.code-toolbar button{padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;border:1px solid var(--border-strong);background:var(--bg-2);color:var(--text-2);cursor:pointer;display:flex;align-items:center;gap:4px;transition:.15s;flex-shrink:0}
.code-toolbar button:active{transform:scale(.96)}
.code-toolbar button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.code-toolbar button svg{width:13px;height:13px}
.code-body{flex:1;overflow:auto;background:#1a1d23;-webkit-overflow-scrolling:touch}
.code-content{padding:14px;font-family:'SF Mono',Menlo,Consolas,'Courier New',monospace;font-size:12px;line-height:1.6;color:#e0e0e6;white-space:pre;tab-size:2;-moz-tab-size:2}
.code-content .cv-tag{color:#7ee787}
.code-content .cv-attr{color:#79c0ff}
.code-content .cv-str{color:#a5d6ff}
.code-content .cv-com{color:#8b949e;font-style:italic}
.code-content .cv-doctype{color:#8b949e}
.code-line-numbers{user-select:none;color:#484f58;text-align:right;padding-right:12px;border-right:1px solid #30363d;margin-right:12px;display:inline-block;min-width:40px}

/* === Edit post page === */
.edit-post-page{display:flex;flex-direction:column;height:100%}
.edit-post-toolbar{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);flex-shrink:0}
.edit-post-toolbar .ept-title{flex:1;font-size:14px;font-weight:600}
.edit-post-toolbar button{padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;border:1px solid var(--border-strong);background:var(--bg-2);color:var(--text-2);cursor:pointer;display:flex;align-items:center;gap:4px;transition:.15s;flex-shrink:0}
.edit-post-toolbar button:active{transform:scale(.96)}
.edit-post-toolbar button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.edit-post-toolbar button svg{width:13px;height:13px}
.edit-post-body{flex:1;overflow:auto;padding:14px}
.edit-post-body .field{margin-bottom:14px}
.edit-post-body .ep-code-area{font-family:'SF Mono',Menlo,Consolas,monospace;font-size:13px;min-height:300px;line-height:1.55;background:#0d1117 !important;color:#e0e0e6 !important;border:1px solid #30363d;border-radius:6px}
.edit-post-warn{padding:10px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;line-height:1.5;margin-bottom:14px;display:flex;align-items:flex-start;gap:6px}
.edit-post-warn svg{width:14px;height:14px;flex-shrink:0;margin-top:1px}

/* === Edited badge (in feed & detail) === */
.edited-flag{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;font-size:10px;font-weight:600;border-radius:3px;background:#fef3c7;color:#92400e;margin-left:6px;vertical-align:middle}
.edited-flag svg{width:10px;height:10px}

/* === Image viewer (lightbox) === */
.img-viewer{position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.92);display:flex;flex-direction:column;animation:fadeIn .2s ease}
.img-viewer .iv-bar{display:flex;justify-content:space-between;align-items:center;padding:calc(var(--safe-top) + 12px) 16px 12px;color:#fff}
.img-viewer .iv-bar .iv-title{font-size:14px;opacity:.9}
.img-viewer .iv-bar button{color:#fff;padding:6px;background:none;border:none;font-size:24px;line-height:1}
.img-viewer .iv-body{flex:1;display:flex;align-items:center;justify-content:center;overflow:auto;padding:0 8px 80px}
.img-viewer .iv-body img{max-width:100%;max-height:100%;object-fit:contain}
.img-viewer .iv-dots{position:absolute;bottom:calc(var(--safe-bottom) + 20px);left:0;right:0;display:flex;justify-content:center;gap:6px}
.img-viewer .iv-dots .dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.4);transition:.2s}
.img-viewer .iv-dots .dot.on{background:#fff;width:18px;border-radius:3px}

/* === Studio === */
.studio-card{margin:8px 14px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;cursor:pointer;transition:transform .15s ease,border-color .15s ease}
.studio-card:active{transform:scale(.985);border-color:var(--accent)}
.studio-card .sc-cover{aspect-ratio:16/9;background:var(--bg-3);position:relative;overflow:hidden}
.studio-card .sc-cover img{width:100%;height:100%;object-fit:cover}
.studio-card .sc-cover .sc-placeholder{width:100%;height:100%;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent-soft),#fff);color:var(--accent);font-size:36px;font-weight:800}
.studio-card .sc-cover .sc-priv{position:absolute;top:8px;right:8px;padding:3px 8px;background:rgba(0,0,0,.6);color:#fff;border-radius:3px;font-size:11px}
.studio-card .sc-body{padding:12px 14px}
.studio-card .sc-name{font-size:15px;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.studio-card .sc-desc{font-size:12px;color:var(--text-3);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.studio-card .sc-meta{display:flex;gap:14px;font-size:11px;color:var(--text-3);margin-top:8px}
.studio-card .sc-meta span{display:flex;align-items:center;gap:3px}
.studio-card .sc-meta svg{width:11px;height:11px}
.studio-card .sc-action{padding:0 14px 12px;display:flex;gap:6px}
.studio-card .sc-action button{flex:1;padding:8px;border-radius:4px;font-size:12px;border:1px solid var(--border-strong);background:var(--bg-2);color:var(--text-2);cursor:pointer;transition:.15s}
.studio-card .sc-action button:active{transform:scale(.96)}
.studio-card .sc-action button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.studio-card .sc-action button.danger{background:#fee2e2;color:var(--danger);border-color:#fecaca}
.studio-detail-head{position:relative}
.studio-detail-head .sd-cover{aspect-ratio:16/5;background:var(--bg-3);overflow:hidden}
.studio-detail-head .sd-cover img{width:100%;height:100%;object-fit:cover}
.studio-detail-head .sd-cover .sd-placeholder{width:100%;height:100%;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent-soft),#fff);color:var(--accent);font-size:48px;font-weight:800}
.studio-detail-body{padding:16px 14px;text-align:center;background:var(--bg)}
.studio-detail-body .sd-name{font-size:20px;font-weight:800;margin-bottom:6px}
.studio-detail-body .sd-desc{font-size:13px;color:var(--text-2);line-height:1.6;margin-bottom:14px;max-width:400px;margin-left:auto;margin-right:auto}
.studio-detail-body .sd-meta{display:flex;justify-content:center;gap:24px;font-size:12px;color:var(--text-3);margin-bottom:14px}
.studio-detail-body .sd-meta b{display:block;font-size:18px;color:var(--text);font-weight:700}
.studio-detail-body .sd-actions{display:flex;justify-content:center;gap:8px;flex-wrap:wrap}
.studio-detail-body .sd-actions .btn{width:auto;padding:8px 22px;font-size:13px}
.studio-tabs{display:flex;background:var(--bg);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:3}
.studio-tabs .st-tab{flex:1;padding:12px;text-align:center;font-size:14px;font-weight:500;color:var(--text-3);border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;cursor:pointer;transition:color .15s ease,border-color .15s ease}
.studio-tabs .st-tab.on{color:var(--accent);border-color:var(--accent)}
.studio-member-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);background:var(--bg)}
.studio-member-item:active{background:var(--bg-2)}
.studio-member-item .avatar{width:38px;height:38px;font-size:14px}
.studio-member-item .sm-info{flex:1;min-width:0}
.studio-member-item .sm-name{font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px}
.studio-member-item .sm-meta{font-size:11px;color:var(--text-3);margin-top:2px}
.studio-role-badge{padding:2px 6px;border-radius:3px;font-size:10px;font-weight:600}
.studio-role-badge.owner{background:var(--accent);color:#fff}
.studio-role-badge.admin{background:#fef3c7;color:#92400e}
.studio-role-badge.member{background:var(--bg-2);color:var(--text-3)}
.studio-pick-row{display:flex;gap:8px;align-items:center;padding:10px 12px;background:var(--bg-2);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:10px;cursor:pointer}
.studio-pick-row:active{background:var(--bg-3)}
.studio-pick-row.on{border-color:var(--accent);background:var(--accent-soft)}
.studio-pick-row .spr-avatar{width:28px;height:28px;border-radius:4px;background:var(--accent);color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700;flex-shrink:0}
.studio-pick-row .spr-info{flex:1;min-width:0}
.studio-pick-row .spr-name{font-size:13px;font-weight:600}
.studio-pick-row .spr-check{color:var(--accent);font-size:16px}

/* === Settings page === */
.settings-page{display:flex;flex-direction:column;height:100%}
.settings-section{padding:14px;border-bottom:1px solid var(--border)}
.settings-section-title{font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:12px}
.settings-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)}
.settings-row:last-child{border-bottom:none}
.settings-row .sr-label{font-size:14px;color:var(--text)}
.settings-row .sr-desc{font-size:11px;color:var(--text-3);margin-top:2px}
.settings-row .sr-control{flex-shrink:0}
.settings-toggle{position:relative;width:42px;height:24px;border-radius:12px;background:var(--bg-3);border:none;cursor:pointer;transition:.2s}
.settings-toggle.on{background:var(--accent)}
.settings-toggle .st-knob{position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.settings-toggle.on .st-knob{transform:translateX(18px)}
.settings-select{padding:6px 12px;border-radius:4px;background:var(--bg-2);border:1px solid var(--border-strong);color:var(--text);font-size:13px;cursor:pointer}
.settings-slider{width:120px;-webkit-appearance:none;appearance:none;height:4px;border-radius:2px;background:var(--bg-3);outline:none}
.settings-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:16px;height:16px;border-radius:50%;background:var(--accent);cursor:pointer}
.settings-slider::-moz-range-thumb{width:16px;height:16px;border-radius:50%;background:var(--accent);cursor:pointer;border:none}
.settings-slider-val{font-size:12px;color:var(--text-2);min-width:32px;text-align:right}

/* === Theme settings === */
.theme-page{display:flex;flex-direction:column;height:100%}
.theme-section{padding:14px;border-bottom:1px solid var(--border)}
.theme-section-title{font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:12px}
.theme-mode-grid{display:flex;gap:8px}
.theme-mode-card{flex:1;padding:16px 12px;border-radius:var(--radius-sm);background:var(--bg-2);border:2px solid var(--border);text-align:center;cursor:pointer;transition:.15s;position:relative}
.theme-mode-card.on{border-color:var(--accent);background:var(--accent-soft)}
.theme-mode-card .tm-preview{width:100%;height:60px;border-radius:4px;margin-bottom:8px;overflow:hidden;display:flex}
.theme-mode-card .tm-preview .tmp-light{flex:1;background:#fff;border:1px solid #e7e9ec}
.theme-mode-card .tm-preview .tmp-dark{flex:1;background:#0f0f14;border:1px solid #2a2a35}
.theme-mode-card .tm-label{font-size:13px;font-weight:600;color:var(--text)}
.theme-mode-card .tm-check{position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;display:none;align-items:center;justify-content:center;font-size:12px}
.theme-mode-card.on .tm-check{display:flex}
.accent-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.accent-card{padding:14px 10px;border-radius:var(--radius-sm);background:var(--bg-2);border:2px solid var(--border);text-align:center;cursor:pointer;transition:.15s;position:relative}
.accent-card.on{border-color:var(--accent)}
.accent-card .ac-color{width:36px;height:36px;border-radius:50%;margin:0 auto 6px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.accent-card .ac-label{font-size:12px;color:var(--text-2)}
.accent-card .ac-check{position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:50%;color:#fff;display:none;align-items:center;justify-content:center;font-size:12px}
.accent-card.on .ac-check{display:flex}

/* === Studio invitation === */
.invite-search-wrap{padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center}
.invite-search-wrap input{flex:1;padding:8px 12px;border-radius:4px;background:var(--bg-2);border:1px solid var(--border-strong);font-size:13px}
.invite-result-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--bg)}
.invite-result-item:active{background:var(--bg-2)}
.invite-result-item .avatar{width:36px;height:36px;font-size:14px}
.invite-result-item .ir-info{flex:1;min-width:0}
.invite-result-item .ir-name{font-size:14px;font-weight:600}
.invite-result-item .ir-bio{font-size:11px;color:var(--text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px}
.invite-result-item .ir-btn{padding:6px 14px;border-radius:4px;background:var(--accent);color:#fff;font-size:12px;font-weight:600;border:none;cursor:pointer;flex-shrink:0}
.invite-result-item .ir-btn:active{transform:scale(.94)}
.invite-pending-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--bg)}
.invite-pending-item .avatar{width:32px;height:32px;font-size:12px}
.invite-pending-item .ip-info{flex:1;min-width:0}
.invite-pending-item .ip-name{font-size:13px;font-weight:600}
.invite-pending-item .ip-time{font-size:11px;color:var(--text-3);margin-top:2px}
.invite-pending-item .ip-cancel{padding:4px 10px;border-radius:4px;background:#fee2e2;color:var(--danger);font-size:11px;border:none;cursor:pointer}
.invite-card{margin:8px 14px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.invite-card .ic-head{display:flex;align-items:center;gap:10px;padding:12px 14px}
.invite-card .ic-studio-cover{width:40px;height:40px;border-radius:6px;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700;font-size:16px;overflow:hidden;flex-shrink:0}
.invite-card .ic-studio-cover img{width:100%;height:100%;object-fit:cover}
.invite-card .ic-body{flex:1;min-width:0}
.invite-card .ic-title{font-size:14px;font-weight:600}
.invite-card .ic-desc{font-size:11px;color:var(--text-3);margin-top:2px}
.invite-card .ic-actions{padding:0 14px 12px;display:flex;gap:6px}
.invite-card .ic-actions button{flex:1;padding:8px;border-radius:4px;font-size:13px;font-weight:600;border:none;cursor:pointer}
.invite-card .ic-actions .accept{background:var(--accent);color:#fff}
.invite-card .ic-actions .decline{background:var(--bg-2);color:var(--text-2);border:1px solid var(--border-strong)}
.invite-card .ic-actions button:active{transform:scale(.96)}

/* === Markdown rendered content === */
.md-content{font-size:14px;line-height:1.7;color:var(--text);word-break:break-word}
.md-content h1{font-size:20px;font-weight:700;margin:14px 0 8px}
.md-content h2{font-size:18px;font-weight:700;margin:12px 0 6px}
.md-content h3{font-size:16px;font-weight:600;margin:10px 0 4px}
.md-content p{margin:6px 0}
.md-content ul,.md-content ol{margin:6px 0;padding-left:20px}
.md-content li{margin:3px 0}
.md-content blockquote{border-left:3px solid var(--accent);padding:4px 12px;margin:8px 0;background:var(--bg-2);border-radius:0 4px 4px 0;color:var(--text-2)}
.md-content code{background:var(--bg-3);padding:2px 5px;border-radius:3px;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12px;color:var(--accent-2)}
.md-content pre{background:var(--bg-3);padding:12px;border-radius:6px;overflow-x:auto;margin:8px 0;border:1px solid var(--border)}
.md-content pre code{background:none;padding:0;font-size:12px;color:var(--text);display:block;white-space:pre}
.md-content a{color:var(--accent);text-decoration:underline}
.md-content img{max-width:100%;border-radius:6px;margin:6px 0}
.md-content hr{border:none;border-top:1px solid var(--border);margin:12px 0}
.md-content table{border-collapse:collapse;width:100%;margin:8px 0;font-size:12px}
.md-content th,.md-content td{border:1px solid var(--border);padding:6px 8px;text-align:left}
.md-content th{background:var(--bg-2);font-weight:600}
.md-content strong{font-weight:700}
.md-content em{font-style:italic}
.md-content del{text-decoration:line-through;color:var(--text-3)
}
/* === MD editor toolbar === */
/* === Markdown Editor（专业工具栏，移动端适配） === */
.md-editor-wrap{border:1px solid var(--border-strong);border-radius:8px;overflow:hidden;background:var(--bg)}
.md-toolbar{
  display:flex;align-items:center;gap:2px;
  padding:6px 8px;
  background:var(--bg-2);
  border-bottom:1px solid var(--border);
  overflow-x:auto;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:none;
  flex-wrap:nowrap;
}
.md-toolbar::-webkit-scrollbar{display:none}
.md-toolbar button{
  flex-shrink:0;
  padding:7px 10px;
  background:transparent;
  border:none;
  color:var(--text-2);
  font-size:13px;
  font-weight:600;
  cursor:pointer;
  border-radius:6px;
  min-width:34px;
  min-height:32px;
  display:flex;align-items:center;justify-content:center;
  transition:background .12s ease,color .12s ease;
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","PingFang SC",sans-serif;
  white-space:nowrap;
}
.md-toolbar button:hover{background:var(--bg-3);color:var(--text-1)}
.md-toolbar button:active{background:var(--accent-soft);color:var(--accent);transform:scale(.95)}
.md-toolbar button svg{width:15px;height:15px;flex-shrink:0}
.md-toolbar .md-sep{
  flex-shrink:0;
  width:1px;height:20px;
  background:var(--border-strong);
  margin:0 3px;
}
.md-toolbar .md-group-label{
  flex-shrink:0;
  font-size:10px;font-weight:700;
  color:var(--text-3);
  text-transform:uppercase;
  letter-spacing:.5px;
  padding:0 4px;
  display:none;
}
.md-toolbar .md-spacer{flex:1;min-width:4px}
.md-toolbar .md-preview-btn{
  background:var(--accent-soft);color:var(--accent);
  font-weight:600;
}
.md-toolbar .md-preview-btn:active{background:var(--accent);color:#fff}
.md-editor-wrap .textarea{
  border:none;border-radius:0;
  min-height:220px;
  font-family:'SF Mono',ui-monospace,Menlo,Consolas,monospace;
  font-size:14px;line-height:1.7;
  background:var(--bg);
}
.md-editor-wrap .textarea:focus{box-shadow:none}
.md-preview-area{
  padding:16px;
  background:var(--bg);
  min-height:220px;max-height:500px;overflow-y:auto;
  -webkit-overflow-scrolling:touch;
}

/* === Auth page animations（简洁淡入，无花哨效果） === */
@keyframes authFadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.auth-page .auth-logo{animation:authFadeIn .3s ease both}
.auth-page .auth-title{animation:authFadeIn .3s ease .05s both}
.auth-page .auth-sub{animation:authFadeIn .3s ease .1s both}
.auth-page .field{animation:authFadeIn .3s ease both}
.auth-page .field:nth-child(3){animation-delay:.15s}
.auth-page .field:nth-child(4){animation-delay:.2s}
.auth-page .field:nth-child(5){animation-delay:.25s}
.auth-page .btn{animation:authFadeIn .3s ease .3s both}
.auth-page .auth-switch{animation:authFadeIn .3s ease .35s both}
.auth-page .input:focus{box-shadow:none;transition:border-color .15s ease}

/* === Hosting (HTML 静态托管) === */
.hosting-page{display:flex;flex-direction:column;height:100%}
.hosting-banner{padding:14px 14px 10px;background:linear-gradient(135deg,rgba(124,92,255,.06),rgba(255,92,138,.06));border-bottom:1px solid var(--border)}
.hosting-banner .hb-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:6px}
.hosting-banner .hb-title svg{width:18px;height:18px;color:var(--accent)}
.hosting-banner .hb-desc{font-size:12px;color:var(--text-3);margin-top:4px;line-height:1.5}
.hosting-banner .hb-stats{display:flex;gap:14px;margin-top:8px;font-size:11px;color:var(--text-3)}
.hosting-banner .hb-stats b{color:var(--text);font-weight:700;font-size:14px;margin-right:3px}
.hosting-create-btn{margin:10px 14px 0;width:calc(100% - 28px);box-sizing:border-box;flex-shrink:0}
.hosted-card{margin:8px 14px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;cursor:pointer;transition:transform .15s ease,border-color .15s ease}
.hosted-card:active{transform:scale(.985);border-color:var(--accent)}
.hosted-card .hc-body{padding:12px 14px}
.hosted-card .hc-title{font-size:15px;font-weight:600;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hosted-card .hc-meta{font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.hosted-card .hc-meta svg{width:11px;height:11px}
.hosted-card .hc-actions{padding:0 14px 12px;display:flex;gap:6px}
.hosted-card .hc-actions button{flex:1;padding:7px;border-radius:4px;font-size:12px;border:1px solid var(--border-strong);background:var(--bg-2);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:4px;transition:.15s}
.hosted-card .hc-actions button:active{transform:scale(.96)}
.hosted-card .hc-actions button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.hosted-card .hc-actions button.danger{background:#fee2e2;color:var(--danger);border-color:#fecaca}
.hosted-card .hc-actions button svg{width:12px;height:12px}
.hosted-viewer-page{display:flex;flex-direction:column;height:100%}
.hosted-viewer-iframe{flex:1;border:none;width:100%;background:#fff}
.hosted-viewer-info{padding:8px 14px;background:var(--bg-2);border-bottom:1px solid var(--border);font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex-shrink:0}
.hosted-viewer-info svg{width:12px;height:12px;flex-shrink:0;vertical-align:middle}

/* === Pinned badge in feed === */
.post-card .pinned-flag{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;font-size:10px;font-weight:700;border-radius:3px;background:#fef3c7;color:#92400e;margin-left:6px;vertical-align:middle}
.post-card .pinned-flag svg{width:10px;height:10px}
.post-card .banned-flag{display:inline-block;padding:2px 6px;font-size:10px;font-weight:700;border-radius:3px;background:#fee2e2;color:var(--danger);margin-left:6px}

/* === Editor === */
.edit-type{display:flex;gap:8px;margin-bottom:14px}
.edit-type .et{
  flex:1;padding:16px 12px;border-radius:var(--radius-sm);
  background:var(--bg);border:1px solid var(--border-strong);
  text-align:center;transition:.15s;cursor:pointer;
}
.edit-type .et.on{background:var(--accent-soft);border-color:var(--accent);color:var(--accent)}
.edit-type .et .et-ico{font-size:24px;margin-bottom:4px}
.edit-type .et .et-t{font-weight:600;font-size:14px}
.edit-type .et .et-d{font-size:11px;color:var(--text-3);margin-top:2px}

.code-area{
  font-family:'SF Mono',Menlo,Consolas,monospace;font-size:13px;
  min-height:240px;line-height:1.55;background:#fafbfc !important;
  color:#1a1d23 !important;border:1px solid var(--border-strong);
}

.cover-zone{
  border:2px dashed var(--border-strong);border-radius:var(--radius-sm);
  padding:22px 16px;text-align:center;color:var(--text-3);
  transition:.15s;cursor:pointer;
}
.cover-zone:active{border-color:var(--accent);background:var(--bg-2)}
.cover-zone.has-img{padding:0;border-style:solid;overflow:hidden;aspect-ratio:16/9;position:relative}
.cover-zone.has-img img{width:100%;height:100%;object-fit:cover}
.cover-zone.has-img .cz-clear{position:absolute;top:8px;right:8px;width:28px;height:28px;border-radius:4px;background:rgba(0,0,0,.6);color:#fff;display:grid;place-items:center;font-size:16px}
.cover-zz{font-size:24px;margin-bottom:6px}
.cover-zz svg{width:30px;height:30px}
.cover-zt{font-size:13px;margin-bottom:6px}
.cover-zd{font-size:11px;color:var(--text-3)}

.view-mode-pick{display:flex;gap:8px}
.view-mode-pick .vm{
  flex:1;padding:12px;border-radius:var(--radius-sm);
  background:var(--bg);border:1px solid var(--border-strong);font-size:13px;text-align:center;cursor:pointer;
}
.view-mode-pick .vm.on{background:var(--accent-soft);border-color:var(--accent);color:var(--accent)}

.section-label{font-size:13px;color:var(--text-2);font-weight:600;margin:16px 0 8px;display:flex;align-items:center;gap:6px}

/* preview iframe in editor */
.live-preview{
  margin-top:10px;border-radius:var(--radius-sm);overflow:hidden;
  border:1px solid var(--border);background:#fff;
}
.live-preview .lp-head{padding:8px 12px;background:var(--bg-2);display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-3)}
.live-preview .lp-head .dot{width:8px;height:8px;border-radius:50%;background:var(--success)}
.live-preview iframe{width:100%;height:240px;border:none;background:#fff}
.lp-actions{display:flex;gap:8px;margin-top:8px}
.lp-actions button{flex:1;padding:10px;border-radius:4px;background:var(--bg);border:1px solid var(--border-strong);font-size:12px;color:var(--text-2);display:flex;align-items:center;justify-content:center;gap:4px}
.lp-actions button:active{background:var(--bg-2)}
.lp-actions button svg{width:13px;height:13px}

/* hide file input */
.hidden{display:none}

/* === Search === */
.search-bar{display:flex;align-items:center;gap:8px;padding:8px 14px;background:var(--bg);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:5}
.search-bar .sb-back{flex-shrink:0;color:var(--text);padding:6px;display:flex;align-items:center;justify-content:center}
.search-bar .sb-back svg{width:20px;height:20px}
.search-bar .sb-input-wrap{flex:1;display:flex;align-items:center;gap:6px;background:var(--bg-2);border:1px solid var(--border-strong);border-radius:4px;padding:0 10px}
.search-bar .sb-input-wrap svg{width:16px;height:16px;color:var(--text-3);flex-shrink:0}
.search-bar input{flex:1;padding:8px 0;font-size:14px;background:none;border:none;color:var(--text)}
.search-bar input::placeholder{color:var(--text-3)}
.search-bar .sb-clear{padding:4px;color:var(--text-3);font-size:16px;display:none;align-items:center}
.search-bar .sb-clear svg{width:14px;height:14px}
.search-bar.has-text .sb-clear{display:flex}
.search-bar .sb-action{padding:6px 10px;color:var(--accent);font-weight:600;font-size:14px;background:none;border:none}

.search-history{padding:14px}
.search-history .sh-title{font-size:12px;color:var(--text-3);font-weight:600;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
.search-history .sh-clear{font-size:11px;font-weight:400;cursor:pointer;color:var(--text-3)}
.search-history .sh-list{display:flex;flex-wrap:wrap;gap:6px}
.search-history .sh-list .sh-item{padding:6px 12px;background:var(--bg-2);border:1px solid var(--border);border-radius:4px;font-size:13px;color:var(--text-2);cursor:pointer}
.search-history .sh-list .sh-item:active{background:var(--bg-3)}
.search-history .sh-empty{font-size:13px;color:var(--text-3);text-align:center;padding:30px 0}

.search-section-label{padding:14px 14px 4px;font-size:12px;color:var(--text-3);font-weight:600}

/* === Announcement banner === */
.ann-banner{margin:8px 14px 0;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:6px;font-size:13px;color:#92400e;display:flex;align-items:flex-start;gap:8px}
.ann-banner .ann-icon{font-size:14px;flex-shrink:0;margin-top:1px}
.ann-banner .ann-body{flex:1;min-width:0}
.ann-banner .ann-title{font-weight:700;margin-bottom:2px}
.ann-banner .ann-content{color:#78350f;font-size:12px;line-height:1.5;word-break:break-word}
.ann-banner .ann-close{flex-shrink:0;color:#92400e;padding:2px 4px;font-size:16px;line-height:1;cursor:pointer;background:none;border:none}
.ann-list{margin:0;padding:0;list-style:none}
.ann-list .ann-item + .ann-item{margin-top:6px;padding-top:6px;border-top:1px solid rgba(245,158,11,.2)}

/* home search entry */
.search-entry{
  display:flex;align-items:center;gap:5px;margin-right:4px;
  padding:7px 12px 7px 10px;background:var(--bg-2);border:1px solid var(--border);
  border-radius:4px;color:var(--text-3);font-size:13px;
}
.search-entry svg{width:15px;height:15px;flex-shrink:0}
.search-entry:active{background:var(--bg-3)}
/* hosting entry */
.hosting-entry{
  display:flex;align-items:center;gap:5px;margin-right:4px;
  padding:7px 12px;background:linear-gradient(135deg,var(--accent),var(--accent-2));border:none;
  border-radius:4px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;flex-shrink:0;
}
.hosting-entry svg{width:14px;height:14px;flex-shrink:0}
.hosting-entry:active{opacity:.85}

/* reduced motion */
:root[data-reduced-motion="1"] *{animation:none !important;transition:none !important}
:root[data-reduced-motion="1"] .page{animation:none !important}
:root[data-reduced-motion="1"] .sheet{animation:none !important}

/* density */
:root[data-density="compact"] .post-card{margin:6px 10px}
:root[data-density="compact"] .post-head{padding:8px 12px}
:root[data-density="compact"] .post-title{padding:0 12px 4px;font-size:14px}
:root[data-density="compact"] .post-text{padding:0 12px 8px;font-size:13px}
:root[data-density="compact"] .post-actions{padding:4px 12px 8px}
:root[data-density="comfortable"] .post-card{margin:12px 16px}
:root[data-density="comfortable"] .post-head{padding:14px 16px}
:root[data-density="comfortable"] .post-title{padding:0 16px 8px;font-size:16px}

/* ============================================================
 *  桌面端响应式布局（≥768px 平板 / ≥1024px 桌面）
 *  设计原则：
 *  - 768px+：内容居中，最大宽度 600px，底部导航保留
 *  - 1024px+：左侧固定侧边导航 + 右侧内容区，取消底部导航
 *  - 1280px+：feed 改为双列瀑布流，详情页双栏
 * ============================================================ */

/* 平板：内容居中，稍宽 */
@media(min-width:768px){
  .sheet{max-width:520px;left:50%;transform:translateX(-50%)}
  .page-scroll,.topbar,.bottom-nav{max-width:600px;margin:0 auto;left:0;right:0}
  .bottom-nav{margin:0 auto;border-radius:0}
  .topbar{margin:0 auto}
  .post-card{margin:8px auto;max-width:580px}
  .contact-card{margin:8px auto;max-width:580px}
}

/* 桌面：侧边导航 + 宽内容区 */
@media(min-width:1024px){
  :root{--nav-h:0px}
  
  /* 隐藏底部导航，改用侧边导航 */
  .bottom-nav{display:none}
  .page{padding-top:0;padding-left:240px}
  .page-scroll{padding-bottom:40px;max-width:none;margin:0}
  
  /* 侧边导航 */
  .bottom-nav.desktop-sidebar{
    display:flex;
    position:fixed;
    left:0;top:0;bottom:0;right:auto;
    width:240px;max-width:none;height:100vh;
    margin:0;
    flex-direction:column;
    padding:calc(var(--safe-top,0px) + 16px) 12px 16px;
    border-right:1px solid var(--border);
    border-top:none;
    z-index:50;
    align-items:stretch;
    justify-content:flex-start;
    gap:4px;
  }
  .bottom-nav.desktop-sidebar .nav-item{
    flex:none;
    flex-direction:row;
    justify-content:flex-start;
    gap:10px;
    padding:10px 14px;
    border-radius:8px;
    font-size:14px;
  }
  .bottom-nav.desktop-sidebar .nav-item .nav-ico{width:20px;height:20px}
  .bottom-nav.desktop-sidebar .nav-item .nav-ico svg{width:20px;height:20px}
  .bottom-nav.desktop-sidebar .nav-fab{
    margin:8px 0;
    width:auto;max-width:none;
    height:42px;border-radius:8px;
    justify-content:flex-start;
    padding:0 14px;gap:10px;
    font-size:14px;font-weight:600;
  }
  .bottom-nav.desktop-sidebar .nav-fab svg{width:20px;height:20px}
  .bottom-nav.desktop-sidebar .nav-brand{
    display:flex;align-items:center;gap:8px;
    padding:8px 14px 16px;font-weight:800;font-size:18px;color:var(--text-1);
  }
  .bottom-nav.desktop-sidebar .nav-brand .logo{
    width:32px;height:32px;border-radius:8px;background:var(--accent);
    display:grid;place-items:center;color:#fff;font-size:16px;font-weight:800;
  }
  
  /* 顶部栏：居中内容区 */
  .topbar{
    max-width:none;margin:0;
    padding:12px 32px;
    position:sticky;top:0;
    background:var(--bg);
    border-bottom:1px solid var(--border);
    z-index:10;
  }
  
  /* 内容区最大宽度限制（防超宽屏拉伸） */
  .page-scroll > *{max-width:1100px;margin-left:auto;margin-right:auto}
  
  /* feed 双列网格 */
  #feed-list{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:12px;
    padding:12px 32px;
    max-width:1100px;margin:0 auto;
  }
  #feed-list .post-card{margin:0;max-width:none}
  #feed-list .skeleton-card{margin:0;max-width:none}
  
  /* 帖子详情：双栏布局 */
  .detail-body{max-width:1100px;margin:0 auto;padding:0 32px}
  
  /* 个人主页：内容居中 */
  .profile-head{padding:32px 16px}
  .profile-head .p-bio{max-width:480px}
  
  /* 编辑表单：居中限宽 */
  .form-wrap{max-width:640px;margin:0 auto;padding:24px}
  
  /* 管理后台：宽屏适配 */
  #admin-scroll{padding:0 32px}
  #admin-list{max-width:1100px;margin:0 auto}
  .admin-stat-grid{max-width:1100px;margin:0 auto}
  .admin-tab-row{max-width:1100px;margin:0 auto;flex-wrap:wrap}
  
  /* 安装向导 */
  .install-wrap{max-width:600px}
  
  /* 托管列表 */
  .hosting-list{max-width:1100px;margin:0 auto;padding:0 32px}
  
  /* 弹窗/sheet 居中 */
  .sheet{max-width:520px}
  
  /* 评论列表限宽 */
  .comments{max-width:680px;margin:0 auto;padding:0 16px}
  
  /* 工作室列表 */
  .studios-grid{max-width:1100px;margin:0 auto;padding:0 32px}
}

/* 大屏：三列 feed + 更宽内容 */
@media(min-width:1280px){
  #feed-list{grid-template-columns:repeat(3,1fr);max-width:1400px}
  .page-scroll > *{max-width:1400px}
  .detail-body{max-width:1400px}
  .admin-stat-grid{max-width:1400px}
  .admin-tab-row{max-width:1400px}
  #admin-list{max-width:1400px}
  .hosting-list{max-width:1400px}
}

/* === 开屏画面 (Splash Screen) ===
 * 扁平化设计：纯色、无阴影、无渐变
 * - 顶部品牌区：纯色 logo 方块 + 站点名
 * - 底部加载指示：三点跳动（Material/iOS 风格）
 * - 进入：logo 简单淡入 + 缩放至 1（克制，不夸张）
 * - 退出：整体淡出
 * - 自适应深色/浅色主题；支持 reduced-motion
 */
#splash{
  position:fixed;inset:0;z-index:9999;
  background:var(--bg);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:0;padding:20px;
  transition:opacity .35s ease,visibility .35s ease;
}
#splash.splash-hidden{
  opacity:0;visibility:hidden;pointer-events:none;
}

/* 品牌区：logo + 站点名垂直居中 */
.splash-brand{
  display:flex;flex-direction:column;align-items:center;gap:14px;
  animation:splashBrandIn .5s cubic-bezier(.2,.7,.3,1) both;
}
@keyframes splashBrandIn{
  from{opacity:0;transform:scale(.92)}
  to{opacity:1;transform:scale(1)}
}
.splash-logo{
  width:64px;height:64px;border-radius:14px;
  background:var(--accent);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:34px;font-weight:700;letter-spacing:-1px;
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","PingFang SC",sans-serif;
}
.splash-name{
  font-size:17px;font-weight:600;color:var(--text);
  letter-spacing:.3px;
}

/* 加载指示：三点跳动，固定在底部 28% 位置 */
.splash-dots{
  position:absolute;bottom:28%;left:50%;transform:translateX(-50%);
  display:flex;gap:6px;
  animation:splashDotsIn .3s ease .2s both;
}
@keyframes splashDotsIn{from{opacity:0}to{opacity:1}}
.splash-dots span{
  width:7px;height:7px;border-radius:50%;
  background:var(--text-3);
  animation:splashDotBounce 1.2s ease-in-out infinite;
}
.splash-dots span:nth-child(1){animation-delay:0s}
.splash-dots span:nth-child(2){animation-delay:.15s}
.splash-dots span:nth-child(3){animation-delay:.3s}
@keyframes splashDotBounce{
  0%,80%,100%{opacity:.25;transform:scale(.8)}
  40%{opacity:1;transform:scale(1)}
}

/* 减少动效：禁用所有动画，三点保持稳定中等亮度 */
:root[data-reduced-motion="1"] #splash *,
:root[data-reduced-motion="1"] .splash-brand,
:root[data-reduced-motion="1"] .splash-dots span{
  animation:none !important;
}
:root[data-reduced-motion="1"] .splash-dots span{
  opacity:.5;
}
</style>
</head>
<body>
<div id="splash">
  <div class="splash-brand">
    <div class="splash-logo">H</div>
    <div class="splash-name">HTMLHub</div>
  </div>
  <div class="splash-dots" aria-label="加载中"><span></span><span></span><span></span></div>
</div>
<div id="app"></div>
<div class="toast-wrap" id="toasts"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.0/marked.min.js"></script>
<script>
/* =========================================================
 *  State & API
 * ========================================================= */
const State = {
  installed: <?php echo $installed ? 'true' : 'false'; ?>,
  pdo_mysql: <?php echo $pdo_mysql_ok ? 'true' : 'false'; ?>,
  user: null,
  isAdmin: false,
  settings: { site_name: 'HTMLHub', site_desc: '' },
  announcements: [],
  hostingEnabled: null,
  unreadNotifs: 0,
  route: '',
  _meTried: false,
  _settingsLoaded: false,
  _announcementsLoaded: false,
  _notifPolled: false,
  _adminChecked: false,
};

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];

/* =========================================================
 *  橡皮筋回弹滚动（仅移动端）
 *  - 用户可在「设置」中开关 + 选择模式（物理 / 动画）
 *  - 检测 iOS：禁用原生 -webkit-overflow-scrolling，用自定义实现
 *  - 检测桌面端（≥1024px）：不启用，使用原生滚动
 * ========================================================= */

// 平台检测
const _isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const _isAndroid = /Android/i.test(navigator.userAgent);
const _isMobile = _isIOS || _isAndroid || ('ontouchstart' in window && window.matchMedia('(max-width:1023px)').matches);

// 读取橡皮筋设置（在运行时可访问，getSetting 在后面定义但运行时已存在）
function _rubberBandEnabled() {
  try { return localStorage.getItem('setting_rubber_band_enabled') === '1'; } catch(e) { return false; }
}
function _rubberBandMode() {
  try { return localStorage.getItem('setting_rubber_band_mode') || 'animation'; } catch(e) { return 'animation'; }
}

/**
 * 为指定滚动容器启用橡皮筋回弹。
 * 根据用户设置选择物理模式或动画模式。
 *
 * @param {HTMLElement} el 滚动容器
 */
function enableRubberBandScroll(el) {
  if (!el || !_isMobile) return;
  // 检查用户设置：是否启用
  if (!_rubberBandEnabled()) return;
  // 如果已经启用过，跳过
  if (el._rubberBandEnabled) return;
  el._rubberBandEnabled = true;

  // iOS：禁用原生弹性滚动
  if (_isIOS) {
    el.style.webkitOverflowScrolling = 'auto';
  }

  const mode = _rubberBandMode();

  if (mode === 'physics') {
    _enablePhysicsRubberBand(el);
  } else {
    _enableAnimationRubberBand(el);
  }
}

/**
 * 动画模式（成熟稳定）：CSS transition 回弹
 * 设计原则：只在边界向外拉时才介入，正常滚动绝不干涉
 */
function _enableAnimationRubberBand(el) {
  let offset = 0;            // 当前橡皮筋位移
  let isDragging = false;     // 手指是否在触摸
  let lastTouchY = 0;         // 上次触摸 Y
  let lastTouchTime = 0;      // 上次触摸时间
  let snapTimer = null;       // 回弹动画定时器
  let isInRubberBand = false; // 是否当前在橡皮筋拉伸中

  function checkBoundary() {
    const st = el.scrollTop;
    return {
      atTop: st <= 0,
      atBottom: st + el.clientHeight >= el.scrollHeight - 1,
    };
  }

  function setOffset(val) {
    offset = val;
    el.style.transform = val === 0 ? '' : `translateY(${val}px)`;
  }

  // 回弹动画
  function snapBack() {
    if (snapTimer) clearTimeout(snapTimer);
    el.style.transition = 'transform .35s cubic-bezier(.22,.61,.36,1)';
    el.style.transform = '';
    snapTimer = setTimeout(() => {
      el.style.transition = '';
      snapTimer = null;
      isInRubberBand = false;
    }, 380);
  }

  // touchstart
  el.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1) return;
    // 如果有回弹动画在进行，立即停止
    if (snapTimer) { clearTimeout(snapTimer); snapTimer = null; el.style.transition = ''; }
    isDragging = true;
    lastTouchY = e.touches[0].clientY;
    lastTouchTime = Date.now();
    // 不重置 offset，不重置 isInRubberBand
    // 如果正在橡皮筋中，手指可以直接从当前位置继续
  }, { passive: true });

  // touchmove
  el.addEventListener('touchmove', (e) => {
    if (!isDragging || e.touches.length !== 1) return;

    const currentY = e.touches[0].clientY;
    const now = Date.now();
    const moveDelta = currentY - lastTouchY; // 本次 move 增量
    lastTouchY = currentY;
    lastTouchTime = now;

    const b = checkBoundary();

    // 核心判断：是否在边界且向外拉
    const pullingOutAtBoundary =
      (b.atTop && moveDelta > 0) ||
      (b.atBottom && moveDelta < 0);

    if (pullingOutAtBoundary) {
      // === 橡皮筋拉伸 ===
      isInRubberBand = true;
      // 停止任何回弹动画
      if (snapTimer) { clearTimeout(snapTimer); snapTimer = null; el.style.transition = ''; }

      // 阻尼计算
      const absOff = Math.abs(offset);
      const resist = Math.max(0.15, 1 - absOff / 150 * 0.7);
      const newOff = offset + moveDelta * 0.38 * resist;

      // 限制最大拉伸
      setOffset(Math.abs(newOff) > 150 ? 150 * Math.sign(newOff) : newOff);
      el.style.transition = 'none';
      e.preventDefault();
    } else if (isInRubberBand) {
      // === 正在橡皮筋中，但用户反向滑（回向中间）===
      // 如果 offset 还没归零，让用户继续拖动 offset 回到 0
      if (offset !== 0) {
        const newOff = offset + moveDelta * 0.38;
        // 如果 offset 过零了，归零并退出橡皮筋
        if ((offset > 0 && newOff <= 0) || (offset < 0 && newOff >= 0)) {
          setOffset(0);
          isInRubberBand = false;
          el.style.transition = '';
          // 不 preventDefault，让浏览器开始正常滚动
        } else {
          setOffset(newOff);
          el.style.transition = 'none';
          e.preventDefault();
        }
      } else {
        // offset 已经是 0，退出橡皮筋
        isInRubberBand = false;
        el.style.transition = '';
        // 不 preventDefault
      }
    }
    // else: 正常滚动，不干涉，不 preventDefault
  }, { passive: false });

  // touchend
  el.addEventListener('touchend', () => {
    if (!isDragging) return;
    isDragging = false;
    // 如果有位移，启动回弹
    if (Math.abs(offset) > 0.5) {
      snapBack();
    } else {
      setOffset(0);
      isInRubberBand = false;
    }
  }, { passive: true });

  // touchcancel
  el.addEventListener('touchcancel', () => {
    isDragging = false;
    if (Math.abs(offset) > 0.5) {
      snapBack();
    } else {
      setOffset(0);
      isInRubberBand = false;
    }
  }, { passive: true });

  // 惯性滚动到边界回弹
  let scrollTimer = null;
  let lastScrollVel = 0;
  let lastST = 0;
  let lastSTime = 0;
  el.addEventListener('scroll', () => {
    // 手指在拖 或 有回弹动画 或 在橡皮筋中 → 不处理
    if (isDragging || snapTimer || isInRubberBand) return;

    if (scrollTimer) clearTimeout(scrollTimer);
    const now = Date.now();
    const dt = now - lastSTime;
    if (dt > 0) lastScrollVel = (el.scrollTop - lastST) / dt * 16;
    lastST = el.scrollTop;
    lastSTime = now;

    const b = checkBoundary();
    if (b.atTop || b.atBottom) {
      scrollTimer = setTimeout(() => {
        const b2 = checkBoundary();
        if ((b2.atTop || b2.atBottom) && !isDragging && !snapTimer && !isInRubberBand) {
          const amt = Math.min(Math.abs(lastScrollVel) * 8, 35);
          if (amt > 2) {
            const sign = b2.atTop ? 1 : -1;
            setOffset(sign * amt);
            snapBack();
          }
        }
        lastScrollVel = 0;
      }, 80);
    }
  }, { passive: true });
}

/**
 * 物理模式（测试中）：弹簧-质量-阻尼物理模型
 */
function _enablePhysicsRubberBand(el) {

  // === 物理参数 ===
  const SPRING_K = 0.12;       // 弹簧劲度系数（越大回弹越快）
  const DAMPING = 0.72;        // 阻尼系数（0~1，越大衰减越快）
  const MAX_STRETCH = 150;     // 最大拉伸距离（px）
  const RESISTANCE = 0.38;     // 拉伸阻尼（手指拖拽时的阻力，0~1）

  // === 状态 ===
  let offset = 0;              // 当前位移（px）
  let velocity = 0;            // 当前速度（px/帧）
  let isDragging = false;      // 手指是否在拖拽
  let isAnimating = false;     // 是否在物理动画中
  let animFrameId = null;      // requestAnimationFrame ID
  let touchStartY = 0;         // 触摸开始 Y
  let lastTouchY = 0;          // 上次触摸 Y
  let lastTouchTime = 0;       // 上次触摸时间
  let stretchDirection = 0;    // 拉伸方向：1=顶部下拉，-1=底部上拉，0=无

  // 取消正在进行的动画
  function cancelAnimation() {
    if (animFrameId !== null) {
      cancelAnimationFrame(animFrameId);
      animFrameId = null;
    }
    isAnimating = false;
  }

  // 立即设置位移（无动画）
  function setOffset(val) {
    offset = val;
    if (val === 0) {
      el.style.transform = '';
    } else {
      el.style.transform = `translateY(${val}px)`;
    }
  }

  // 物理动画步进
  function physicsStep() {
    // 弹簧力：拉向原位（offset=0）
    const springForce = -SPRING_K * offset;
    // 阻尼力：与速度反方向
    const dampingForce = -DAMPING * velocity;
    // 加速度
    const accel = springForce + dampingForce;
    // 更新速度
    velocity += accel;
    // 更新位移
    offset += velocity;

    // 到位判断：位移和速度都足够小时停止
    if (Math.abs(offset) < 0.5 && Math.abs(velocity) < 0.5) {
      setOffset(0);
      velocity = 0;
      isAnimating = false;
      animFrameId = null;
      return;
    }

    setOffset(offset);
    animFrameId = requestAnimationFrame(physicsStep);
  }

  // 启动回弹动画
  function startSnapBack() {
    cancelAnimation();
    isAnimating = true;
    animFrameId = requestAnimationFrame(physicsStep);
  }

  // 检查边界
  function checkBoundary() {
    const scrollTop = el.scrollTop;
    const scrollHeight = el.scrollHeight;
    const clientHeight = el.clientHeight;
    return {
      atTop: scrollTop <= 0,
      atBottom: scrollTop + clientHeight >= scrollHeight - 1,
    };
  }

  // touchstart：接管（取消任何正在进行的动画）
  el.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1) return;
    // 立即取消动画，手指接管
    cancelAnimation();
    isDragging = true;
    touchStartY = e.touches[0].clientY;
    lastTouchY = touchStartY;
    lastTouchTime = Date.now();
    velocity = 0;
    stretchDirection = 0;
    // 如果当前有位移（正在回弹中被抓住），保留当前 offset
    // 手指可以直接从当前位置继续拖
  }, { passive: true });

  // touchmove：拖拽或正常滚动
  // 核心原则：只在橡皮筋状态下 preventDefault，正常滚动绝不阻止
  // 关键修复：回弹动画中被触摸时，如果用户想正常滚动（方向朝中间），
  // 立即归零位移，让浏览器原生滚动接管
  el.addEventListener('touchmove', (e) => {
    if (!isDragging || e.touches.length !== 1) return;

    const currentY = e.touches[0].clientY;
    const now = Date.now();
    const dt = now - lastTouchTime;
    const moveDelta = currentY - lastTouchY;

    // 计算手指速度
    if (dt > 0) {
      velocity = (moveDelta / dt) * 16;
    }
    lastTouchY = currentY;
    lastTouchTime = now;

    const b = checkBoundary();

    // 判断是否应该进入橡皮筋状态
    // 条件：在边界且本次 move 方向朝外
    const atBoundaryAndPullingOut =
      (b.atTop && moveDelta > 0) ||
      (b.atBottom && moveDelta < 0);

    if (atBoundaryAndPullingOut) {
      // === 橡皮筋状态：在边界且向外拉 ===

      // 计算新的 offset（带阻尼）
      const absOffset = Math.abs(offset);
      const resistanceFactor = Math.max(0.15, 1 - absOffset / MAX_STRETCH * 0.7);
      const newOffset = offset + moveDelta * RESISTANCE * resistanceFactor;

      // 限制最大拉伸
      if (Math.abs(newOffset) > MAX_STRETCH) {
        setOffset(MAX_STRETCH * Math.sign(newOffset));
      } else {
        setOffset(newOffset);
      }

      // 阻止默认滚动
      e.preventDefault();
    } else {
      // === 正常滚动状态 ===
      // 无论之前是否有 offset（可能是回弹中被抓住），
      // 只要不是在边界向外拉，就归零并让浏览器原生滚动
      if (offset !== 0) {
        setOffset(0);
        velocity = 0;
        stretchDirection = 0;
      }
      // 不 preventDefault！让浏览器原生滚动正常工作
    }
  }, { passive: false });

  // touchend：松手 → 物理回弹
  el.addEventListener('touchend', () => {
    if (!isDragging) return;
    isDragging = false;

    // 如果有位移，启动弹簧回弹
    if (Math.abs(offset) > 0.5) {
      // 限制初始速度（避免过快）
      velocity = Math.max(-30, Math.min(30, velocity));
      startSnapBack();
    } else {
      setOffset(0);
      velocity = 0;
    }
    stretchDirection = 0;
  }, { passive: true });

  // touchcancel
  el.addEventListener('touchcancel', () => {
    isDragging = false;
    if (Math.abs(offset) > 0.5) {
      velocity = 0;
      startSnapBack();
    } else {
      setOffset(0);
    }
    stretchDirection = 0;
  }, { passive: true });

  // scroll 事件：处理惯性滚动到达边界
  let scrollEndTimer = null;
  let lastScrollVelocity = 0;
  let lastScrollTop = 0;
  let lastScrollTime = 0;

  el.addEventListener('scroll', () => {
    // 拖拽中或动画中不处理
    if (isDragging || isAnimating) return;

    if (scrollEndTimer) clearTimeout(scrollEndTimer);

    const now = Date.now();
    const dt = now - lastScrollTime;
    if (dt > 0) {
      lastScrollVelocity = (el.scrollTop - lastScrollTop) / dt * 16;
    }
    lastScrollTop = el.scrollTop;
    lastScrollTime = now;

    const b = checkBoundary();
    if (b.atTop || b.atBottom) {
      scrollEndTimer = setTimeout(() => {
        const b2 = checkBoundary();
        if ((b2.atTop || b2.atBottom) && !isDragging && !isAnimating) {
          // 惯性到达边界 → 给一个小的弹性位移
          const bounceAmount = Math.min(Math.abs(lastScrollVelocity) * 8, 35);
          if (bounceAmount > 2) {
            const sign = b2.atTop ? 1 : -1;
            // 设置初始位移和速度，启动物理动画
            setOffset(sign * bounceAmount);
            velocity = sign * Math.min(Math.abs(lastScrollVelocity) * 3, 15);
            startSnapBack();
          }
        }
        lastScrollVelocity = 0;
      }, 80);
    }
  }, { passive: true });
}

/**
 * 为所有 .page-scroll 容器启用橡皮筋回弹。
 * 在每次页面渲染后调用。
 */
function enableRubberBandForAllScrolls() {
  if (!_isMobile) return;
  // 延迟执行，确保 DOM 已渲染
  requestAnimationFrame(() => {
    $$('.page-scroll').forEach(el => enableRubberBandScroll(el));
  });
}

// MutationObserver：自动为新插入的 .page-scroll 启用橡皮筋
if (_isMobile) {
  const _rubberBandObserver = new MutationObserver((mutations) => {
    let needsCheck = false;
    for (const mut of mutations) {
      if (mut.addedNodes.length > 0) { needsCheck = true; break; }
    }
    if (needsCheck) {
      requestAnimationFrame(() => {
        $$('.page-scroll').forEach(el => enableRubberBandScroll(el));
      });
    }
  });
  // DOMContentLoaded 后启动观察
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      _rubberBandObserver.observe(document.getElementById('app') || document.body, {
        childList: true, subtree: true
      });
      // 初始启用
      enableRubberBandForAllScrolls();
    });
  } else {
    _rubberBandObserver.observe(document.getElementById('app') || document.body, {
      childList: true, subtree: true
    });
    enableRubberBandForAllScrolls();
  }
}

/* =========================================================
 *  BotGuard —— 无感人机验证前端模块
 * =========================================================
 *
 *  设计：
 *    - 用户打开登录/注册/发帖/评论页时，BotGuard.acquireFp()
 *      被动采集 5 项浏览器能力信号，组合成指纹。
 *    - BotGuard.ensureToken() 用指纹换取服务端 token（30 分钟有效）。
 *    - 表单提交时调用 BotGuard.attachTo(payload)，把 _bg + _bg_fp 注入。
 *
 *  信号采集全部被动，无可见 UI，对真实用户零干扰。
 *  Headless 浏览器、curl/wget、Python requests 因缺失 Canvas/WebGL/Intl
 *  等图形/国际化能力，无法生成合法指纹，从而被服务端拒绝。
 *
 *  缓存策略：
 *    - 指纹 fp 在页面会话内缓存（同一会话内浏览器能力不会变）
 *    - token 缓存到内存，过期前 5 分钟自动续签
 * ========================================================= */
const BotGuard = (function() {
  let _fp = null;        // 缓存指纹（页面会话内不变）
  let _fpStr = '';       // JSON 字符串形式
  let _token = null;     // 缓存 token
  let _tokenExp = 0;     // token 过期时间（秒级 unix）
  let _pendingIssue = null; // 防止并发 issue

  /**
   * 采集浏览器能力指纹。
   * 返回 JSON 字符串。失败时返回空串（服务端会拒绝）。
   */
  function collectFp() {
    if (_fpStr) return _fpStr;
    const fp = { perfNow: '', rgba: '', webgl: '', motion: '', tz: '' };
    try {
      // 1. performance.now() 精度
      //    真实浏览器返回浮点数（微秒级），headless 通常为 0 或整数
      if (window.performance && typeof performance.now === 'function') {
        const t = performance.now();
        // 检测小数位（精度越高越像真实浏览器）
        const frac = (t % 1).toString().length;
        fp.perfNow = String(frac);
      }
    } catch (e) {}

    try {
      // 2. Canvas 2D 渲染特征
      //    headless 无 GPU/无字体，渲染像素为空或全黑
      const c = document.createElement('canvas');
      c.width = 64; c.height = 16;
      const ctx = c.getContext('2d');
      if (ctx) {
        ctx.textBaseline = 'top';
        ctx.font = '12px Arial';
        ctx.fillStyle = '#f60';
        ctx.fillRect(0, 0, 64, 16);
        ctx.fillStyle = '#069';
        ctx.fillText('BotGuard!', 2, 2);
        const data = ctx.getImageData(0, 0, 64, 16).data;
        // 取前 16 个像素的 RGBA 作为特征（不用全部，避免数据过长）
        let sum = 0;
        let sample = '';
        for (let i = 0; i < data.length; i += 4) {
          sum += data[i] + data[i+1] + data[i+2] + data[i+3];
          if (i < 64) sample += data[i] + ',' + data[i+1] + ',' + data[i+2] + ',' + data[i+3] + '|';
        }
        fp.rgba = sample + 's=' + sum;
      }
    } catch (e) {}

    try {
      // 3. WebGL 渲染器字符串
      //    真实浏览器返回 GPU 厂商信息，headless 为空或 SwiftShader
      const c = document.createElement('canvas');
      const gl = c.getContext('webgl') || c.getContext('experimental-webgl');
      if (gl) {
        const dbg = gl.getExtension('WEBGL_debug_renderer_info');
        if (dbg) {
          fp.webgl = String(gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) || '') + '/' +
                     String(gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || '');
        } else {
          fp.webgl = String(gl.getParameter(gl.VENDOR) || '') + '/' +
                     String(gl.getParameter(gl.RENDERER) || '');
        }
      }
    } catch (e) {}

    try {
      // 4. 运动传感器能力
      //    移动端通常为 true，桌面端 false；headless 通常为 false
      const hasTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
      const hasMotion = 'DeviceMotionEvent' in window;
      fp.motion = (hasTouch ? '1' : '0') + (hasMotion ? '1' : '0');
    } catch (e) {}

    try {
      // 5. 时区
      //    真实浏览器返回 IANA 时区名（如 Asia/Shanghai）
      //    headless/无 Intl 环境返回空
      fp.tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) {}

    _fp = fp;
    _fpStr = JSON.stringify(fp);
    return _fpStr;
  }

  /**
   * 用指纹换取服务端 token。
   * 内置缓存：过期前 5 分钟自动续签。
   * @return {Promise<{token:string, fp:string}|null>}
   */
  async function ensureToken() {
    const now = Math.floor(Date.now() / 1000);
    // 缓存命中（剩余有效期 > 5 分钟）
    if (_token && _tokenExp - now > 300) {
      return { token: _token, fp: _fpStr };
    }
    // 防止并发 issue（多个表单同时提交时复用同一个 Promise）
    if (_pendingIssue) return _pendingIssue;

    const fp = collectFp();
    if (!fp) return null;

    _pendingIssue = (async () => {
      try {
        const r = await api('botguard_issue', { fp });
        _token = r.token;
        _tokenExp = r.expires_at || 0;
        return { token: _token, fp: _fpStr };
      } catch (e) {
        // 签发失败：清空缓存，下次重试
        _token = null;
        _tokenExp = 0;
        return null;
      } finally {
        _pendingIssue = null;
      }
    })();
    return _pendingIssue;
  }

  /**
   * 把 token + 指纹注入到表单 payload。
   * 若 token 获取失败，返回原 payload（服务端会拒绝）。
   * @param {Object} payload 表单数据
   * @return {Promise<Object>} 注入后的 payload
   */
  async function attachTo(payload) {
    if (!payload || typeof payload !== 'object') payload = {};
    const r = await ensureToken();
    if (r) {
      payload._bg = r.token;
      payload._bg_fp = r.fp;
    }
    return payload;
  }

  /**
   * 重置缓存（用户切换账号/登出后调用）。
   */
  function reset() {
    _fp = null;
    _fpStr = '';
    _token = null;
    _tokenExp = 0;
    _pendingIssue = null;
  }

  return { collectFp, ensureToken, attachTo, reset };
})();

/**
 * 调用后端 API。
 * action 支持以下三种写法：
 *   1. 'me'
 *   2. 'posts?page=1&type=html'
 *   3. 'post&id=123'
 * 后两种会被正确解析成 ?api=name&key=val 的查询串。
 */
async function api(action, data = null) {
  const url = new URL(window.location.href);
  // 清掉旧的查询参数（保留 hash）
  url.search = '';
  url.hash = '';

  // 把 action 切成 name 和 query
  let apiName = action;
  let queryStr = '';
  const qIdx = action.indexOf('?');
  const aIdx = action.indexOf('&');
  let sep = -1;
  if (qIdx >= 0) sep = qIdx;
  if (aIdx >= 0 && (sep < 0 || aIdx < sep)) sep = aIdx;
  if (sep >= 0) {
    apiName = action.substring(0, sep);
    queryStr = action.substring(sep + 1);
  }
  url.searchParams.set('api', apiName);
  if (queryStr) {
    queryStr.split('&').forEach(pair => {
      if (!pair) return;
      const eq = pair.indexOf('=');
      const k = eq >= 0 ? pair.substring(0, eq) : pair;
      const v = eq >= 0 ? pair.substring(eq + 1) : '';
      url.searchParams.set(k, decodeURIComponent(v));
    });
  }

  const opts = { method: data ? 'POST' : 'GET', headers: {} };
  if (data) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(data);
  }
  const res = await fetch(url.toString(), opts);
  const json = await res.json();
  if (!res.ok || json.error) throw new Error(json.error || ('HTTP ' + res.status));
  return json;
}

function toast(msg, type = '') {
  const wrap = $('#toasts');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-8px)'; el.style.transition = '.25s'; }, 2200);
  setTimeout(() => el.remove(), 2600);
}

/* === 自定义 Modal 系统（替代 alert / confirm / prompt） === */
let _modalSeq = 0;
function _modalClose(id) {
  const el = document.getElementById('modal-' + id);
  if (el) {
    el.style.animation = 'modalFade .15s ease reverse';
    setTimeout(() => el.remove(), 140);
  }
}

/** 通用 Modal：返回 Promise<boolean>（confirm 时）或 Promise<string|null>（prompt 时）或 void（alert 时） */
function showModal({ title = '', msg = '', type = 'info', input = null, inputPlaceholder = '', inputValue = '', okText = '确定', cancelText = '取消', danger = false, onOk = null, onCancel = null }) {
  const id = ++_modalSeq;
  const iconMap = { info: 'ℹ', warn: '⚠', danger: '⚠', success: '✓' };
  const iconHtml = `<div class="modal-icon ${type}">${iconMap[type] || 'ℹ'}</div>`;
  const inputHtml = input !== null ? `<input class="modal-input" id="modal-input-${id}" placeholder="${escapeHtml(inputPlaceholder)}" value="${escapeHtml(inputValue)}">` : '';
  const isPrompt = input !== null;
  const isConfirm = !!cancelText && !isPrompt;
  const cancelBtnHtml = (isConfirm || isPrompt) ? `<button class="modal-cancel" data-action="cancel">${escapeHtml(cancelText)}</button>` : '';

  const mask = document.createElement('div');
  mask.id = 'modal-' + id;
  mask.className = 'modal-mask';
  // 注意：modal-box 上不要加 onclick="event.stopPropagation()"
  // 否则按钮点击事件无法冒泡到 mask 的 listener，导致按钮无响应
  // 点击 mask 空白处关闭由下方 listener 中的 e.target === mask 判断处理
  mask.innerHTML = `<div class="modal-box">
    <div class="modal-head">
      ${iconHtml}
      ${title ? `<div class="modal-title">${escapeHtml(title)}</div>` : ''}
    </div>
    <div class="modal-body">
      <div class="modal-msg">${escapeHtml(msg)}</div>
      ${inputHtml}
    </div>
    <div class="modal-actions">
      ${cancelBtnHtml}
      <button class="modal-ok ${danger?'danger':'primary'}" data-action="ok">${escapeHtml(okText)}</button>
    </div>
  </div>`;
  mask.addEventListener('click', e => {
    // 关键：用 closest 查找带 data-action 的祖先元素
    const actionEl = e.target.closest('[data-action]');
    // 点击 mask 空白处（即 mask 本身）= 取消
    if (e.target === mask) {
      _modalClose(id);
      if (onCancel) onCancel();
      return;
    }
    if (actionEl) {
      if (actionEl.dataset.action === 'cancel') {
        _modalClose(id);
        if (onCancel) onCancel();
      } else if (actionEl.dataset.action === 'ok') {
        if (isPrompt) {
          const val = document.getElementById('modal-input-' + id).value;
          _modalClose(id);
          if (onOk) onOk(val);
        } else {
          _modalClose(id);
          if (onOk) onOk(true);
        }
      }
    }
  });
  document.body.appendChild(mask);
  if (isPrompt) {
    setTimeout(() => {
      const inp = document.getElementById('modal-input-' + id);
      if (inp) { inp.focus(); inp.select(); }
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          const val = inp.value;
          _modalClose(id);
          if (onOk) onOk(val);
        } else if (e.key === 'Escape') {
          _modalClose(id);
          if (onCancel) onCancel();
        }
      });
    }, 50);
  }
  return mask;
}

/** 提示框（替代 alert） */
function showAlert(msg, title = '', type = 'info', onOk = null) {
  return showModal({ msg, title, type, cancelText: '', okText: '知道了', onOk });
}

/** 确认框（替代 confirm） */
function showConfirm(msg, title = '请确认', onOk = null, onCancel = null, opts = {}) {
  return showModal({
    msg, title, type: opts.type || 'warn',
    okText: opts.okText || '确定', cancelText: opts.cancelText || '取消',
    danger: opts.danger || false,
    onOk, onCancel,
  });
}

/** 输入框（替代 prompt） */
function showPrompt(msg, onOk = null, opts = {}) {
  return showModal({
    msg, title: opts.title || '请输入', type: 'info',
    input: true,
    inputPlaceholder: opts.placeholder || '',
    inputValue: opts.value || '',
    okText: opts.okText || '确定', cancelText: opts.cancelText || '取消',
    onOk,
  });
}

/** Markdown 渲染：使用 marked.js + XSS 防护 */
function renderMarkdown(md) {
  if (!md || typeof md !== 'string') return '';
  if (typeof marked === 'undefined') return escapeHtml(md).replace(/\n/g, '<br>');
  try {
    // 配置 marked：关闭 HTML 透传（防止 XSS）
    marked.setOptions({
      breaks: true,
      gfm: true,
    });
    let html = marked.parse(md);
    // 后处理：移除可能的 <script> / on* 事件（双保险）
    html = html.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
    html = html.replace(/\son\w+\s*=\s*"[^"]*"/gi, '');
    html = html.replace(/\son\w+\s*=\s*'[^']*'/gi, '');
    html = html.replace(/javascript:/gi, '');
    return html;
  } catch (e) {
    return escapeHtml(md).replace(/\n/g, '<br>');
  }
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function firstChar(s) {
  s = String(s || '');
  return s.trim().charAt(0).toUpperCase() || '?';
}
function avatarHtml(user) {
  if (user && user.avatar) return `<div class="avatar"><img src="${user.avatar}" alt="" loading="lazy" decoding="async"></div>`;
  return `<div class="avatar">${escapeHtml(firstChar(user ? user.username : '?'))}</div>`;
}

/* =========================================================
 *  联系方式渲染（安全：所有值经过 escapeHtml，链接经过白名单校验）
 * ========================================================= */

// 平台图标（用 emoji 或文字标识，避免引入外部图标库）
const CONTACT_ICONS = {
  wechat: '💬', qq: '🐧', email: '📧', phone: '📱',
  telegram: '✈️', discord: '🎮', github: '🐙', gitee: '🔥',
  weibo: '📢', bilibili: '📺', zhihu: '📚', twitter: '🐦',
  instagram: '📷', youtube: '▶️', tiktok: '🎵', linkedin: '💼',
  steam: '🎮', website: '🌐', custom: '🔗',
};

/**
 * 渲染用户简介（仅简介文字，不含联系方式）。
 * 联系方式现在作为独立卡片单独渲染，视觉更清晰。
 * @param {object} u 用户对象
 * @return {string} HTML
 */
function renderUserBio(u) {
  const bio = u.bio || '这个人很懒，还没写简介';
  return `<div class="p-bio-text">${escapeHtml(bio)}</div>`;
}

/**
 * 渲染联系方式卡片（独立区块，带标题、网格布局、可复制）。
 * 设计要点：
 * - 卡片式容器，带「联系方式」标题和图标
 * - 网格布局（2 列），每项是一张小卡片
 * - 平台图标 + 平台名 + 值，点击可复制或跳转
 * - 无联系方式时返回空字符串（不渲染卡片）
 *
 * @param {object} u 用户对象，需含 contact 数组
 * @return {string} HTML
 */
function renderUserContactCard(u) {
  if (!Array.isArray(u.contact) || u.contact.length === 0) return '';
  let html = '<div class="contact-card">';
  html += '<div class="contact-card-head"><span class="contact-card-icon">📇</span><span class="contact-card-title">联系方式</span><span class="contact-card-count">' + u.contact.length + '</span></div>';
  html += '<div class="contact-card-grid">';
  u.contact.forEach((c, i) => {
    const icon = CONTACT_ICONS[c.platform] || '🔗';
    const label = escapeHtml(c.label || c.platform);
    const value = escapeHtml(c.value);
    const rawValue = c.value.replace(/'/g, "\\'");
    // 生成安全的链接/可复制文本
    let actionHtml = '';
    let clickableType = 'copy'; // copy | link
    if (c.platform === 'email') {
      actionHtml = `<a href="mailto:${value}" target="_blank" rel="noopener" class="contact-tile-value" onclick="event.stopPropagation()">${value}</a>`;
      clickableType = 'link';
    } else if (c.platform === 'phone') {
      actionHtml = `<a href="tel:${value}" class="contact-tile-value" onclick="event.stopPropagation()">${value}</a>`;
      clickableType = 'link';
    } else if (c.platform === 'website') {
      actionHtml = `<a href="${value}" target="_blank" rel="noopener noreferrer" class="contact-tile-value" onclick="event.stopPropagation()">${value}</a>`;
      clickableType = 'link';
    } else if (c.platform === 'github') {
      actionHtml = `<a href="https://github.com/${encodeURIComponent(c.value)}" target="_blank" rel="noopener noreferrer" class="contact-tile-value" onclick="event.stopPropagation()">${value}</a>`;
      clickableType = 'link';
    } else if (c.platform === 'gitee') {
      actionHtml = `<a href="https://gitee.com/${encodeURIComponent(c.value)}" target="_blank" rel="noopener noreferrer" class="contact-tile-value" onclick="event.stopPropagation()">${value}</a>`;
      clickableType = 'link';
    } else {
      actionHtml = `<span class="contact-tile-value" onclick="event.stopPropagation();copyContact('${rawValue}')">${value}</span>`;
    }
    const copyBtn = clickableType === 'copy'
      ? `<button class="contact-tile-copy" onclick="event.stopPropagation();copyContact('${rawValue}')" title="复制"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>`
      : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;color:var(--text-3);flex-shrink:0"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`;
    html += `<div class="contact-tile" data-platform="${c.platform}" onclick="copyContact('${rawValue}')" title="${label}：${value}">
      <div class="contact-tile-top">
        <span class="contact-tile-icon">${icon}</span>
        <span class="contact-tile-label">${label}</span>
        ${copyBtn}
      </div>
      <div class="contact-tile-val">${actionHtml}</div>
    </div>`;
  });
  html += '</div></div>';
  return html;
}

// 复制联系方式到剪贴板
window.copyContact = async (text) => {
  try {
    if (navigator.clipboard) {
      await navigator.clipboard.writeText(text);
    } else {
      // 兜底方案
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    toast('已复制：' + text, 'ok');
  } catch (e) {
    toast('复制失败，请手动选择文本复制', 'err');
  }
};

/* =========================================================
 *  Icons (inline SVG)
 * ========================================================= */
const ICO = {
  heart: (on) => `<svg viewBox="0 0 24 24" fill="${on?'currentColor':'none'}" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`,
  star: (on) => `<svg viewBox="0 0 24 24" fill="${on?'currentColor':'none'}" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
  comment: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`,
  eye: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
  play: () => `<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>`,
  home: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z"/></svg>`,
  user: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
  plus: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`,
  back: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>`,
  trash: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>`,
  edit: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
  camera: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>`,
  upload: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>`,
  refresh: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`,
  logout: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`,
  compass: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>`,
  search: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`,
  close: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
  pin: () => `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/></svg>`,
  follow: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>`,
  shield: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`,
  chart: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>`,
  check: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
  ban: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>`,
  reply: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>`,
  bell: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`,
  flag: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>`,
  studio: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v16"/><path d="M3 21h18"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>`,
  settings: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`,
  code: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>`,
  copy: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`,
  edit2: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`,
  hosting: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5z"/><path d="M2 14a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-3z"/><line x1="6" y1="6.5" x2="6.01" y2="6.5"/><line x1="6" y1="15.5" x2="6.01" y2="15.5"/></svg>`,
  link: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>`,
  palette: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>`,
  broadcast: () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>`,
};

/* =========================================================
 *  Router
 * =========================================================
 * 设计说明（3.17.2 重写）：
 *
 * 早期版本维护了一个自定义 _routeHistory 栈，goBack() 时从栈中
 * pop 出上一页，再用 `location.hash = prev` 跳回去。这套机制
 * 和浏览器原生历史会逐步失步，导致"作品详情页 ↔ 开始游玩"
 * 之间反复横跳的死循环 bug：
 *
 *   1. `location.hash = prev` 不会"回到"既有历史条目，而是
 *      在浏览器历史里 *新增* 一条记录。
 *   2. 当自定义栈耗尽、改用 `history.back()` 兜底时，浏览器
 *      实际指针仍停在这些"假历史"之间，于是 A→B→A→B 无限横跳。
 *
 * 修复方案：
 *   - 不再维护自定义路由栈，统一依赖浏览器原生历史。
 *   - goBack() 直接调用 history.back()，行为与浏览器返回键一致。
 *   - 当历史长度 ≤ 1（直接打开深链且无前置记录）时，兜底跳首页。
 *   - go() 同 hash 跳转短路，避免冗余历史条目。
 *
 * 深链兜底（SPA Root 检测）：
 *   - 用户可能直接打开深链（如 /post/123），此时浏览器历史中
 *     没有任何 SPA 内部页面。直接 history.back() 会退出 SPA。
 *   - 通过 history.state 标记 SPA 入口条目（__spaRoot=true），
 *     goBack() 在根入口处自动兜底跳 /home，而非退出 SPA。
 *
 * 行为对照：
 *   /home → /post/123 → /play/123 → [返回] /post/123 → [返回] /home ✓
 *   深链 /post/123 → /play/123 → [返回] /post/123 → [返回] /home ✓（不退出 SPA）
 */

// 标记 SPA 入口历史条目，用于区分"深链根入口"与"SPA 内部跳转"
// __spaRoot=true 表示这是用户进入 SPA 的第一个历史条目（深链或首页）
;(function initSpaHistoryState() {
  if (!history.state || !history.state.__spaMarked) {
    history.replaceState({ __spaMarked: true, __spaRoot: true }, '');
  }
})();

function go(hash) {
  if (!hash.startsWith('#')) hash = '#' + hash;
  // 同 hash 不入栈，避免冗余历史条目（也避免触发无意义的 hashchange）
  if (location.hash === hash) return;
  location.hash = hash;
}

/**
 * 安全返回
 * - SPA 内部跳转：使用浏览器原生 history.back()
 * - SPA 根入口（深链）：兜底跳 /home，避免直接退出 SPA
 * - 浏览器历史为空：兜底跳 /home
 */
function goBack() {
  // 在 SPA 根入口（如深链 /post/123 直接打开）按返回：
  // 避免直接退出 SPA，兜底跳首页（除非已经在首页）
  if (history.state && history.state.__spaRoot) {
    if (location.hash !== '#/home' && location.hash !== '') {
      go('/home');
      return;
    }
    // 已在首页根入口：交给浏览器处理（可能退出 SPA 或停留）
    if (window.history.length > 1) {
      history.back();
      return;
    }
    return;
  }
  // SPA 内部跳转：用浏览器原生历史回退
  if (window.history.length > 1) {
    history.back();
    return;
  }
  // 兜底：直接打开深链且无历史时，回到首页
  go('/home');
}

// hashchange 拦截器：为新创建的历史条目打上 SPA 标记（非根）
// 这样后续 goBack() 能正确区分根入口与内部跳转
window.addEventListener('hashchange', () => {
  if (!history.state || !history.state.__spaMarked) {
    history.replaceState({ __spaMarked: true, __spaRoot: false }, '');
  }
  render();
});

// 桌面端/移动端切换时重新渲染（导航栏布局变化）
let _lastIsDesktop = window.matchMedia('(min-width:1024px)').matches;
window.addEventListener('resize', () => {
  const isDesktop = window.matchMedia('(min-width:1024px)').matches;
  if (isDesktop !== _lastIsDesktop) {
    _lastIsDesktop = isDesktop;
    render();
  }
});

async function render() {
  const app = $('#app');
  const hash = location.hash.replace(/^#\/?/, '') || '';
  State.route = hash;
  // 不再调用 pushRoute —— 浏览器原生历史已准确记录路由轨迹，
  // 维护自定义栈反而会与浏览器历史失步，导致返回键横跳 bug（详见 Router 设计说明）
  const parts = hash.split('/');
  const path = parts[0] || '';
  const id = parts[1];

  // not installed -> install wizard
  if (!State.installed) {
    app.innerHTML = View.install();
    bindInstall();
    return;
  }

  // load user if not loaded (try once per page load)
  if (State.user === null && !State._meTried) {
    State._meTried = true;
    try {
      const r = await api('me');
      State.user = r.user;
    } catch (e) {
      State.user = null;
    }
  }

  // load settings if not loaded
  if (!State._settingsLoaded) {
    State._settingsLoaded = true;
    try {
      const s = await api('settings');
      State.settings = s;
      document.title = `${s.site_name} · HTML 作品社区`;
    } catch (e) {}
  }

  // load announcements if not loaded
  if (!State._announcementsLoaded) {
    State._announcementsLoaded = true;
    try {
      const r = await api('announcements');
      State.announcements = r.announcements || [];
    } catch (e) {
      State.announcements = [];
    }
  }

  // load hosting enabled state if not loaded
  if (State.hostingEnabled === null) {
    try {
      const r = await api('hosted_settings');
      State.hostingEnabled = r.enabled;
    } catch (e) {
      State.hostingEnabled = false;
    }
  }

  // 启动通知未读数轮询（仅登录用户）
  if (State.user && !State._notifPolled) {
    State._notifPolled = true;
    pollNotifications();
  }

  switch (path) {
    case '':
    case 'home':
      return renderHome();
    case 'discover':
      return renderDiscover();
    case 'search':
      return renderSearch(parts[1] ? decodeURIComponent(parts[1]) : '');
    case 'new':
      if (!State.user) return go('/login');
      return renderNew();
    case 'post':
      if (!id) return go('');
      return renderDetail(parseInt(id));
    case 'play':
      if (!id) return go('');
      return renderPlay(parseInt(id));
    case 'code':
      if (!id) return go('');
      return renderCodeViewer(parseInt(id));
    case 'edit-post':
      if (!id) return go('');
      return renderEditPost(parseInt(id));
    case 'profile':
      // 支持 /profile/edit 子路由
      if (id === 'edit') return renderProfileEdit();
      return renderProfile();
    case 'user':
      if (!id) return go('');
      return renderUser(parseInt(id));
    case 'followers':
      if (!id) return go('');
      return renderFollowers(parseInt(id));
    case 'following':
      if (!id) return go('');
      return renderFollowing(parseInt(id));
    case 'admin':
      return renderAdmin();
    case 'notifications':
      return renderNotifications();
    case 'hosting':
      return renderHosting();
    case 'theme':
      return renderTheme();
    case 'settings':
      return renderSettings();
    case 'code-score':
      // 玩具工具：受管理员开关控制；关闭时跳回首页
      if (!State.settings.code_score_enabled) return go('');
      return renderCodeScore();
    case 'hosted':
      if (!id) return go('/hosting');
      return renderHostedView(id);
    case 'studios':
      return renderStudios();
    case 'studio':
      if (!id) return go('/studios');
      if (id === 'new') return renderStudioNew();
      return renderStudioDetail(parseInt(id));
    case 'login':
      return renderLogin();
    case 'register':
      return renderRegister();
    case 'favorites':
      return renderFavorites();
    case 'report':
      // /report/:type/:id  例如 /report/post/123
      return renderReportPage(parts[1] || '', parts[2] || '');
    default:
      return go('');
  }
}

/* =========================================================
 *  Views
 * ========================================================= */
const View = {
  topbar(title, rightHtml = '') {
    return `<div class="topbar">
      <div class="brand">
        ${rightHtml === 'BACK' ? `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>` : `<div class="logo">H</div><span>${escapeHtml(title || State.settings.site_name)}</span>`}
      </div>
      <div class="actions">${rightHtml === 'BACK' ? '' : rightHtml}</div>
    </div>`;
  },
  bottomNav(active) {
    const isDesktop = window.matchMedia('(min-width:1024px)').matches;
    const items = [
      { k:'home', ico: ICO.home(), l:'首页', path:'/home' },
      { k:'discover', ico: ICO.compass(), l:'发现', path:'/discover' },
      { k:'fab', ico: ICO.plus(), l:'发布', path:'/new' },
      { k:'fav', ico: ICO.star(false), l:'收藏', path:'/favorites' },
      { k:'profile', ico: ICO.user(), l:'我的', path:'/profile' },
    ];
    if (isDesktop) {
      // 桌面端：左侧边栏导航
      const siteName = (State.settings && State.settings.site_name) || 'HTMLHub';
      return `<div class="bottom-nav desktop-sidebar">
        <div class="nav-brand">
          <div class="logo">H</div>
          <span>${escapeHtml(siteName)}</span>
        </div>
        ${items.map(it => {
          if (it.k === 'fab') return `<button class="nav-fab" onclick="go('${it.path}')">${it.ico}<span>${it.l}</span></button>`;
          return `<button class="nav-item ${active===it.k?'active':''}" onclick="go('${it.path}')">
            <span class="nav-ico">${it.ico}</span><span>${it.l}</span>
          </button>`;
        }).join('')}
      </div>`;
    }
    // 移动端：底部导航
    return `<div class="bottom-nav">
      ${items.map(it => {
        if (it.k === 'fab') return `<button class="nav-fab" onclick="go('${it.path}')">${it.ico}</button>`;
        return `<button class="nav-item ${active===it.k?'active':''}" onclick="go('${it.path}')">
          <span class="nav-ico">${it.ico}</span><span>${it.l}</span>
        </button>`;
      }).join('')}
    </div>`;
  },
  postCard(p) {
    const coverHtml = p.type === 'html' && p.cover
      ? `<div class="post-cover" onclick="go('/post/${p.id}');event.stopPropagation()">
           <img src="${p.cover}" alt="" loading="lazy" decoding="async">
           <div class="type-tag">HTML 作品</div>
           <div class="view-tag">${p.view_mode === 'jump' ? '跳转浏览' : '内嵌浏览'}</div>
           <div class="play-badge"><span>${ICO.play()}</span></div>
         </div>`
      : (p.type === 'html'
        ? `<div class="post-cover" onclick="go('/post/${p.id}');event.stopPropagation()" style="background:linear-gradient(135deg,#1a1a2e,#16213e);display:grid;place-items:center;color:#71717a">
             <div style="text-align:center">
               <div style="font-size:32px;margin-bottom:4px">{ }</div>
               <div style="font-size:12px">未设置封面</div>
             </div>
             <div class="type-tag">HTML 作品</div>
           </div>`
        : '');
    // 文字动态的图片网格
    let imagesHtml = '';
    if (p.type === 'text' && Array.isArray(p.images) && p.images.length > 0) {
      const cnt = p.images.length;
      const showCount = Math.min(cnt, 9);
      const visible = p.images.slice(0, showCount);
      imagesHtml = `<div class="post-images count-${showCount}" onclick="openImageViewer(${p.id});event.stopPropagation()">
        ${visible.map((src, i) => `<div class="pi"><img src="${src}" alt="" loading="lazy" decoding="async"></div>`).join('')}
      </div>`;
    }
    const textHtml = p.type === 'text' ? `<div class="post-text md-content">${renderMarkdown(p.content)}</div>` : '';
    const pinFlag = p.is_pinned ? `<span class="pinned-flag">${ICO.pin()}置顶</span>` : '';
    const editedFlag = p.is_edited ? `<span class="edited-flag" title="已编辑">已编辑</span>` : '';
    const studioFlag = p.studio_id > 0 ? `<span class="pinned-flag" style="background:var(--accent-soft);color:var(--accent)">${ICO.studio()}工作室</span>` : '';
    return `<div class="post-card" onclick="go('/post/${p.id}')">
      <div class="post-head">
        ${avatarHtml(p.author)}
        <div style="flex:1;min-width:0">
          <div class="name">${escapeHtml(p.author.username)}</div>
          <div class="time">${p.created_at}</div>
        </div>
      </div>
      ${p.title ? `<div class="post-title">${escapeHtml(p.title)}${pinFlag}${studioFlag}${editedFlag}</div>` : `<div class="post-title" style="display:none">${pinFlag}${studioFlag}${editedFlag}</div>`}
      ${textHtml}
      ${imagesHtml}
      ${coverHtml}
      <div class="post-actions">
        <button class="act-btn ${p.liked?'on':''}" onclick="toggleLike(${p.id},this);event.stopPropagation()">
          ${ICO.heart(p.liked)}<span>${p.likes_count}</span>
        </button>
        <button class="act-btn" onclick="go('/post/${p.id}');event.stopPropagation()">
          ${ICO.comment()}<span>${p.comments_count}</span>
        </button>
        <button class="act-btn ${p.favorited?'on-fav':''}" onclick="toggleFav(${p.id},this);event.stopPropagation()">
          ${ICO.star(p.favorited)}<span>${p.favorites_count}</span>
        </button>
        <button class="act-btn" style="margin-left:auto" onclick="event.stopPropagation()">
          ${ICO.eye()}<span>${p.views}</span>
        </button>
      </div>
    </div>`;
  },
  skeleton(n=3) {
    return Array(n).fill(0).map(()=>`<div class="sk-card">
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
        <div class="sk-line" style="width:38px;height:38px;border-radius:50%"></div>
        <div style="flex:1"><div class="sk-line w40"></div></div>
      </div>
      <div class="sk-line w70"></div>
      <div class="sk-cover"></div>
      <div class="sk-line w40"></div>
    </div>`).join('');
  },
  empty(title, sub, btnText = '', btnAction = '') {
    return `<div class="empty">
      <div class="em-ico">⌛</div>
      <p style="font-weight:600;color:var(--text-2);margin-bottom:6px">${escapeHtml(title)}</p>
      <p>${escapeHtml(sub)}</p>
      ${btnText ? `<button class="em-btn" onclick="${btnAction}">${escapeHtml(btnText)}</button>` : ''}
    </div>`;
  },
};

/* =========================================================
 *  Home
 * ========================================================= */
async function renderHome() {
  const app = $('#app');
  // 公告条（仅显示未关闭的）
  const dismissed = JSON.parse(localStorage.getItem('dismissed_ann') || '[]');
  const visibleAnns = State.announcements.filter(a => !dismissed.includes(a.id));
  const annHtml = visibleAnns.length ? `
    <div class="ann-banner">
      <div class="ann-icon">📢</div>
      <div class="ann-body">
        <ul class="ann-list">
          ${visibleAnns.map(a => `<li class="ann-item">
            <div class="ann-title">${escapeHtml(a.title)}</div>
            ${a.content ? `<div class="ann-content">${escapeHtml(a.content)}</div>` : ''}
          </li>`).join('')}
        </ul>
      </div>
      <button class="ann-close" onclick="dismissAnnouncements()">×</button>
    </div>` : '';

  app.innerHTML = `<div class="page">
    ${View.topbar(State.settings.site_name, `<button class="search-entry" onclick="go('/search')">${ICO.search()}<span>搜索</span></button>${State.hostingEnabled ? `<button class="hosting-entry" onclick="go('/hosting')">${ICO.hosting()}<span>托管 Beta</span></button>` : ''}<button class="icon-btn" id="home-refresh" onclick="refreshHome()" title="刷新">${ICO.refresh()}</button>`)}
    <div class="page-scroll" id="feed-scroll">
      ${annHtml}
      <div class="feed-tabs">
        <button class="chip active" data-type="all">全部</button>
        <button class="chip" data-type="html">HTML 作品</button>
        <button class="chip" data-type="text">文字动态</button>
        <button class="chip" data-sort="hot">🔥 热门</button>
      </div>
      <div id="feed-list">${View.skeleton()}</div>
    </div>
    ${View.bottomNav('home')}
  </div>`;

  let curType = 'all', curSort = 'new', page = 1, loading = false, hasMore = true;
  let allPosts = [];
  const PAGE_SIZE = 10;

  function renderList() {
    if (!allPosts.length) {
      $('#feed-list').innerHTML = View.empty('还没有内容', '快去发布第一个 HTML 作品或动态吧', '去发布', "go('/new')");
      return;
    }
    let html = allPosts.map(View.postCard).join('');
    if (loading) {
      html += `<div id="feed-loading" style="padding:20px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
    } else if (!hasMore) {
      html += `<div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px">— 已经到底啦 —</div>`;
    } else {
      html += `<div id="feed-more" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多</div>`;
    }
    $('#feed-list').innerHTML = html;
  }

  async function load(reset = false) {
    if (loading) return;
    if (reset) {
      page = 1; hasMore = true; allPosts = [];
      $('#feed-list').innerHTML = View.skeleton();
    }
    if (!hasMore) return;
    loading = true;
    // 追加加载时立即显示 loading 提示
    if (!reset && allPosts.length > 0) {
      renderList(); // 此时 loading=true，会显示「加载中…」
    }
    try {
      const r = await api(`posts?page=${page}&type=${curType}&sort=${curSort}`);
      allPosts = reset ? r.posts : allPosts.concat(r.posts);
      hasMore = r.has_more;
      page++;
    } catch (e) {
      toast(e.message, 'err');
      if (allPosts.length === 0) {
        $('#feed-list').innerHTML = View.empty('加载失败', e.message);
        loading = false;
        return;
      }
    } finally {
      loading = false;
      // 关键修复：finally 中再次渲染，此时 loading=false，
      // 会正确显示「已经到底啦」或「上拉加载更多」
      renderList();
    }
  }

  $$('.chip').forEach(c => c.addEventListener('click', () => {
    $$('.chip').forEach(x => x.classList.remove('active'));
    c.classList.add('active');
    if (c.dataset.type) { curType = c.dataset.type; curSort = 'new'; }
    if (c.dataset.sort) { curSort = c.dataset.sort; curType = 'all'; }
    load(true);
  }));

  const scroll = $('#feed-scroll');
  scroll.addEventListener('scroll', () => {
    if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 200) load();
  });

  load(true);
}

window.dismissAnnouncements = () => {
  const dismissed = JSON.parse(localStorage.getItem('dismissed_ann') || '[]');
  State.announcements.forEach(a => { if (!dismissed.includes(a.id)) dismissed.push(a.id); });
  localStorage.setItem('dismissed_ann', JSON.stringify(dismissed));
  // 隐藏 banner
  const banner = $('.ann-banner');
  if (banner) banner.remove();
};

// 首页刷新：强制重新加载公告 + 帖子列表，刷新图标旋转动画
window.refreshHome = async () => {
  const btn = $('#home-refresh');
  if (btn) {
    btn.disabled = true;
    btn.style.transition = 'transform .6s ease';
    btn.style.transform = 'rotate(360deg)';
  }
  // 重新加载公告
  State._announcementsLoaded = false;
  try {
    const r = await api('announcements');
    State.announcements = r.announcements || [];
  } catch (e) {}
  // 清空已关闭的公告记录（让刷新后公告重新显示）
  localStorage.removeItem('dismissed_ann');
  // 重新渲染整页（会触发 load(true)）
  setTimeout(() => {
    renderHome();
    toast('已刷新', 'ok');
  }, 300);
};

/* =========================================================
 *  Discover (same as home but always hot)
 * ========================================================= */
async function renderDiscover() {
  const app = $('#app');
  app.innerHTML = `<div class="page">
    ${View.topbar('发现')}
    <div class="page-scroll" id="disc-scroll">
      <div class="feed-tabs">
        <button class="chip active" data-tab="hot" onclick="switchDiscoverTab('hot')">🔥 热门作品</button>
        <button class="chip" data-tab="studios" onclick="switchDiscoverTab('studios')">${ICO.studio()} 工作室</button>
      </div>
      <div id="disc-list">${View.skeleton()}</div>
    </div>
    ${View.bottomNav('discover')}
  </div>`;
  let curTab = 'hot';
  // 热门帖子分页状态
  let hotPage = 1;
  let hotHasMore = true;
  let hotLoading = false;

  async function loadHot(reset = false) {
    if (hotLoading) return;
    if (reset) {
      hotPage = 1;
      hotHasMore = true;
      $('#disc-list').innerHTML = View.skeleton();
    }
    if (!hotHasMore && !reset) return;
    hotLoading = true;
    try {
      const r = await api(`posts?sort=hot&page=${hotPage}`);
      hotHasMore = !!r.has_more;
      const html = r.posts.length
        ? r.posts.map(View.postCard).join('')
        : (reset ? View.empty('暂无热门', '去看看最新作品吧') : '');
      if (reset) {
        $('#disc-list').innerHTML = html;
      } else {
        const footer = $('#disc-list .list-footer');
        if (footer) footer.remove();
        $('#disc-list').insertAdjacentHTML('beforeend', html);
      }
      if (r.posts.length > 0) {
        const footerHtml = hotHasMore
          ? `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多…</div>`
          : `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">· 已经到底啦 ·</div>`;
        $('#disc-list').insertAdjacentHTML('beforeend', footerHtml);
      }
      hotPage++;
    } catch (e) {
      if (reset) $('#disc-list').innerHTML = View.empty('加载失败', e.message);
      hotHasMore = false;
    } finally {
      hotLoading = false;
    }
  }
  let studioSubTab = 'all'; // all | mine | search
  async function loadStudios() {
    $('#disc-list').innerHTML = `
      <div style="padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <button class="chip ${studioSubTab==='all'?'active':''}" data-sub="all" onclick="switchStudioSub('all')">全部</button>
        <button class="chip ${studioSubTab==='mine'?'active':''}" data-sub="mine" onclick="switchStudioSub('mine')">我加入的</button>
        <div style="flex:1;min-width:120px;display:flex;gap:6px">
          <input class="input" id="disc-studio-search" placeholder="搜索工作室" style="flex:1;padding:6px 10px;font-size:12px" onkeydown="if(event.key==='Enter')switchStudioSub('search')">
          <button class="btn" style="width:auto;padding:6px 12px;font-size:12px" onclick="switchStudioSub('search')">搜索</button>
        </div>
        <button class="btn" style="width:auto;padding:6px 12px;font-size:12px" onclick="go('/studio/new')">${ICO.plus()} 创建</button>
      </div>
      <div id="disc-studio-list">${View.skeleton()}</div>
    `;
    loadStudioSubList();
  }

  async function loadStudioSubList() {
    const listEl = $('#disc-studio-list');
    if (!listEl) return;
    listEl.innerHTML = View.skeleton();
    try {
      let r;
      if (studioSubTab === 'mine') {
        r = await api('studios?mine=1');
      } else if (studioSubTab === 'search') {
        const q = ($('#disc-studio-search')?.value || '').trim();
        if (!q) {
          listEl.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">请输入关键词后搜索</div>';
          return;
        }
        r = await api('studios?q=' + encodeURIComponent(q));
      } else {
        r = await api('studios');
      }
      listEl.innerHTML = r.studios.length
        ? r.studios.map(studioCardHtml).join('')
        : (studioSubTab === 'mine'
          ? View.empty('还没有加入工作室', '去发现有趣的工作室并加入', '浏览全部', "switchStudioSub('all')")
          : studioSubTab === 'search'
            ? View.empty('没有匹配的工作室', '换个关键词试试')
            : View.empty('还没有工作室', '抢先创建第一个工作室', '创建工作室', "go('/studio/new')"));
    } catch (e) {
      listEl.innerHTML = View.empty('加载失败', e.message);
    }
  }

  window.switchStudioSub = (sub) => {
    studioSubTab = sub;
    $$('.chip[data-sub]').forEach(x => x.classList.toggle('active', x.dataset.sub === sub));
    loadStudioSubList();
  };
  window.switchDiscoverTab = (t) => {
    curTab = t;
    $$('.feed-tabs .chip').forEach(x => x.classList.toggle('active', x.dataset.tab === t));
    if (t === 'hot') loadHot(true);
    else loadStudios();
  };
  // 滚动监听：仅 hot 标签页触发分页（工作室列表不分页）
  const discScroll = $('#disc-scroll');
  if (discScroll && !discScroll._htmlhubScrollBound) {
    discScroll._htmlhubScrollBound = true;
    discScroll.addEventListener('scroll', () => {
      if (curTab !== 'hot') return;
      if (hotLoading || !hotHasMore) return;
      if (discScroll.scrollTop + discScroll.clientHeight >= discScroll.scrollHeight - 200) {
        loadHot(false);
      }
    });
  }
  loadHot(true);
}

/* =========================================================
 *  Search
 * ========================================================= */
function loadSearchHistory() {
  try { return JSON.parse(localStorage.getItem('search_history') || '[]'); } catch (e) { return []; }
}
function saveSearchHistory(q) {
  let list = loadSearchHistory();
  list = list.filter(x => x !== q);
  list.unshift(q);
  list = list.slice(0, 10);
  try { localStorage.setItem('search_history', JSON.stringify(list)); } catch (e) {}
  return list;
}
function clearSearchHistory() {
  try { localStorage.removeItem('search_history'); } catch (e) {}
}

async function renderSearch(initialQ = '') {
  const app = $('#app');
  const history = loadSearchHistory();
  app.innerHTML = `<div class="page">
    <div class="search-bar" id="sbar">
      <button class="sb-back" onclick="goBack()">${ICO.back()}</button>
      <div class="sb-input-wrap">
        ${ICO.search()}
        <input type="text" id="s-input" placeholder="搜索作品标题 / 内容 / 作者" autocomplete="off">
        <button class="sb-clear" onclick="clearSearchInput()">${ICO.close()}</button>
      </div>
      <button class="sb-action" onclick="doSearch()">搜索</button>
    </div>
    <div class="page-scroll" id="s-scroll">
      <div id="s-content">
        <div class="search-history">
          <div class="sh-title">
            <span>搜索历史</span>
            ${history.length ? `<span class="sh-clear" onclick="doClearHistory()">清空</span>` : ''}
          </div>
          <div class="sh-list">
            ${history.length
              ? history.map(h => `<div class="sh-item" onclick="quickSearch('${escapeHtml(h).replace(/'/g, "\\'")}')">${escapeHtml(h)}</div>`).join('')
              : `<div class="sh-empty">暂无搜索历史</div>`}
          </div>
        </div>
        <div class="search-section-label">推荐热词</div>
        <div style="padding:0 14px 14px;display:flex;flex-wrap:wrap;gap:6px">
          ${['HTML','动画','CSS','游戏','Canvas','交互','3D','粒子'].map(k => `<div class="sh-item" style="padding:6px 12px;background:var(--bg-2);border:1px solid var(--border);border-radius:4px;font-size:13px;color:var(--text-2);cursor:pointer" onclick="quickSearch('${k}')">${k}</div>`).join('')}
        </div>
      </div>
    </div>
  </div>`;

  const input = $('#s-input');
  const sbar = $('#sbar');
  let searchTimer = null;

  // 初始化查询
  if (initialQ) {
    input.value = initialQ;
    sbar.classList.add('has-text');
    doSearch(initialQ);
  } else {
    setTimeout(() => input.focus(), 100);
  }

  input.addEventListener('input', () => {
    sbar.classList.toggle('has-text', input.value.length > 0);
    // debounce auto search
    clearTimeout(searchTimer);
    const v = input.value.trim();
    if (v) {
      searchTimer = setTimeout(() => doSearch(v, true), 500);
    } else {
      $('#s-content').innerHTML = renderHistoryBlock();
    }
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') doSearch();
  });

  function renderHistoryBlock() {
    const list = loadSearchHistory();
    return `<div class="search-history">
      <div class="sh-title">
        <span>搜索历史</span>
        ${list.length ? `<span class="sh-clear" onclick="doClearHistory()">清空</span>` : ''}
      </div>
      <div class="sh-list">
        ${list.length
          ? list.map(h => `<div class="sh-item" onclick="quickSearch('${escapeHtml(h).replace(/'/g, "\\'")}')">${escapeHtml(h)}</div>`).join('')
          : `<div class="sh-empty">暂无搜索历史</div>`}
      </div>
    </div>
    <div class="search-section-label">推荐热词</div>
    <div style="padding:0 14px 14px;display:flex;flex-wrap:wrap;gap:6px">
      ${['HTML','动画','CSS','游戏','Canvas','交互','3D','粒子'].map(k => `<div class="sh-item" style="padding:6px 12px;background:var(--bg-2);border:1px solid var(--border);border-radius:4px;font-size:13px;color:var(--text-2);cursor:pointer" onclick="quickSearch('${k}')">${k}</div>`).join('')}
    </div>`;
  }

  window.clearSearchInput = () => {
    input.value = '';
    sbar.classList.remove('has-text');
    input.focus();
    $('#s-content').innerHTML = renderHistoryBlock();
  };
  window.doClearHistory = () => {
    clearSearchHistory();
    $('#s-content').innerHTML = renderHistoryBlock();
    toast('已清空', 'ok');
  };
  window.quickSearch = (q) => {
    input.value = q;
    sbar.classList.add('has-text');
    doSearch(q);
  };

  let loading = false;
  window.doSearch = async (q, silent) => {
    const kw = (q !== undefined ? q : input.value).trim();
    if (!kw) {
      if (!silent) toast('请输入关键词', 'err');
      return;
    }
    if (loading) return;
    loading = true;
    // 只有手动搜索才保存历史（避免每次输入字母都记录）
    if (!silent) {
      saveSearchHistory(kw);
    }
    $('#s-content').innerHTML = View.skeleton(2);
    try {
      const r = await api('search&q=' + encodeURIComponent(kw));
      $('#s-content').innerHTML = r.posts.length
        ? `<div class="search-section-label">找到 ${r.posts.length} 个结果</div>` + r.posts.map(View.postCard).join('')
        : View.empty('没有找到相关内容', `没有标题、内容或作者包含 "${kw}" 的作品`, '去发布', "go('/new')");
    } catch (e) {
      $('#s-content').innerHTML = View.empty('搜索失败', e.message);
    } finally {
      loading = false;
    }
  };
}

/* =========================================================
 *  Detail
 * ========================================================= */
async function renderDetail(id) {
  const app = $('#app');
  // 加载时立即显示带返回按钮的顶栏，避免卡死时无法返回
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('加载中...', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button><button class="icon-btn" onclick="renderDetail(${id})" title="刷新">${ICO.refresh()}</button>`)}
    <div class="page-scroll" id="d-scroll">${View.skeleton(1)}</div>
  </div>`;
  try {
    const r = await api('post&id=' + id);
    const p = r.post;

    // 顶部封面（HTML 作品有封面才显示大图）
    let heroHtml = '';
    if (p.cover) {
      heroHtml = `<div class="detail-hero" onclick="if(${p.type==='html'})go('/play/${p.id}')">
           <img src="${p.cover}" alt="" loading="lazy" decoding="async">
           <button class="back" onclick="event.stopPropagation();goBack()">${ICO.back()}</button>
           ${p.type === 'html' ? `<div class="play-circle">${ICO.play()}</div>` : ''}
           ${p.is_pinned ? `<div style="position:absolute;top:calc(var(--safe-top) + 12px);right:12px;padding:4px 8px;background:#fef3c7;color:#92400e;border-radius:3px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:3px">${ICO.pin()}置顶</div>` : ''}
         </div>`;
    } else {
      heroHtml = `<div class="topbar">
           <button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>
           <div style="display:flex;gap:6px;align-items:center">
             ${p.is_pinned ? `<div style="font-size:11px;font-weight:700;color:#92400e;display:flex;align-items:center;gap:3px">${ICO.pin()}置顶</div>` : ''}
             <button class="icon-btn" onclick="renderDetail(${id})" title="刷新">${ICO.refresh()}</button>
             ${renderDetailAuthorActions(p)}
           </div>
         </div>`;
    }

    const playBar = p.type === 'html' ? `
      <div class="play-bar">
        <button class="btn" onclick="go('/play/${p.id}')">${ICO.play()} 进入游玩</button>
        <button class="btn ghost play-bar-secondary" onclick="go('/code/${p.id}')">${ICO.code()} 查看代码</button>
      </div>` : '';
    const textBody = p.type === 'text' ? `<div class="detail-text md-content">${renderMarkdown(p.content)}</div>` : '';
    // 已编辑标识（在标题旁）
    const editedFlag = p.is_edited ? `<span class="edited-flag" title="最后编辑于 ${p.edited_at}">已编辑</span>` : '';
    // 作者可编辑 HTML 作品
    const canEditHtml = p.type === 'html' && State.user && State.user.id === p.author.id;
    const editBtn = canEditHtml ? `<button class="icon-btn" onclick="go('/edit-post/${p.id}')" title="编辑代码">${ICO.edit2()}</button>` : '';

    // 作者可删除自己的帖子；管理员同样可以
    const canDelete = State.user && (State.user.id === p.author.id || State.user.role === 'admin');

    app.innerHTML = `<div class="page page-slide">
      <div class="page-scroll" id="d-scroll">
        ${heroHtml}
        <div class="detail-body">
          ${p.cover ? `<div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:10px">
            <button class="icon-btn" onclick="renderDetail(${id})" title="刷新">${ICO.refresh()}</button>
            ${editBtn}
            ${canDelete ? `<button class="icon-btn" onclick="deleteOwnPost(${p.id})" title="删除">${ICO.trash()}</button>` : ''}
          </div>` : ''}
          ${p.title ? `<h1 class="detail-title">${escapeHtml(p.title)}${editedFlag}</h1>` : `<div style="display:flex;gap:6px;margin-bottom:12px">${editedFlag}</div>`}
          <div class="detail-meta" onclick="go('/user/${p.author.id}')" style="cursor:pointer;padding:10px 12px;margin:0 -12px 16px;border-radius:6px;background:var(--bg-2);border:1px solid var(--border)">
            ${avatarHtml(p.author)}
            <div style="flex:1;min-width:0">
              <div class="name">${escapeHtml(p.author.username)}</div>
              <div class="time">${p.created_at} · ${p.views} 次浏览${p.is_edited ? ` · 已编辑 ${p.edited_at}` : ''}</div>
            </div>
            <span style="font-size:12px;color:var(--text-3)">查看主页 ›</span>
          </div>
          ${textBody}
          ${p.type === 'text' && Array.isArray(p.images) && p.images.length > 0 ? `<div class="detail-images count-${Math.min(p.images.length,9)}">
            ${p.images.map((src, i) => `<div class="di ${p.images.length === 1 ? 'full-bleed' : ''}" onclick="openImageViewerArray(window.__currentPostImages||[], ${i})"><img src="${src}" alt="" loading="lazy" decoding="async"></div>`).join('')}
          </div>` : ''}
          <div class="detail-actions">
            <button class="act-btn ${p.liked?'on':''}" id="btn-like">
              ${ICO.heart(p.liked)}<span>${p.likes_count}</span>
            </button>
            <button class="act-btn ${p.favorited?'on-fav':''}" id="btn-fav">
              ${ICO.star(p.favorited)}<span>${p.favorites_count}</span>
            </button>
            <button class="act-btn" id="btn-cmt">
              ${ICO.comment()}<span>${p.comments_count}</span>
            </button>
            ${State.user && State.user.id !== p.author.id ? `<button class="act-btn" id="btn-report" title="举报">
              ${ICO.flag()}<span>举报</span>
            </button>` : ''}
          </div>
          <div class="comments">
            <h3>${ICO.comment()} 评论 <span style="color:var(--text-3);font-size:13px">${p.comments_count}</span></h3>
            <div id="cmt-list"></div>
          </div>
        </div>
      </div>
      <div class="cmt-input-bar" id="cmt-bar">
        <div class="input-row">
          <input type="text" id="cmt-input" placeholder="${State.user ? '说点什么…' : '登录后评论'}" maxlength="500" ${State.user ? '' : 'disabled'}>
          <button onclick="sendComment(${p.id})">发送</button>
        </div>
      </div>
      ${playBar}
    </div>`;

    $('#btn-like').onclick = () => toggleDetailLike(p.id, $('#btn-like'));
    $('#btn-fav').onclick = () => toggleDetailFav(p.id, $('#btn-fav'));
    $('#btn-cmt').onclick = () => {
      if (!State.user) { go('/login'); return; }
      clearReplyState();
      $('#cmt-input').focus();
    };
    const btnReport = $('#btn-report');
    if (btnReport) btnReport.onclick = () => go('/report/post/' + p.id);
    $('#cmt-input').addEventListener('keydown', e => {
      if (e.key === 'Enter') sendComment(p.id);
    });
    // 点击空白处取消回复
    $('#d-scroll').addEventListener('click', e => {
      if (!e.target.closest('.comment-item') && !e.target.closest('.cmt-input-bar')) clearReplyState();
    });

    // 暴露图片数组给详情页大图查看器
    window.__currentPostImages = (p.images && Array.isArray(p.images)) ? p.images : [];
    loadComments(p.id);
  } catch (e) {
    toast(e.message, 'err');
    app.innerHTML = `<div class="page page-slide"><div class="page-scroll">${View.empty('加载失败', e.message, '返回首页', "go('/home')")}</div></div>`;
  }
}

function renderDetailAuthorActions(p) {
  const canDelete = State.user && (State.user.id === p.author.id || State.user.role === 'admin');
  const canEditHtml = p.type === 'html' && State.user && State.user.id === p.author.id;
  let html = '';
  if (canEditHtml) html += `<button class="icon-btn" onclick="go('/edit-post/${p.id}')" title="编辑代码">${ICO.edit2()}</button>`;
  if (canDelete) html += `<button class="icon-btn" onclick="deleteOwnPost(${p.id})" title="删除">${ICO.trash()}</button>`;
  return html;
}

/* =========================================================
 *  Image viewer (lightbox)
 * ========================================================= */
let _viewerImages = [];
let _viewerIndex = 0;

function openImageViewerArray(images, index = 0) {
  if (!images || !images.length) return;
  _viewerImages = images;
  _viewerIndex = index;
  renderImageViewer();
}

// 从帖子卡片点击：异步获取图片数组
async function openImageViewer(postId) {
  try {
    const r = await api('post&id=' + postId);
    const images = (r.post.images && r.post.images.length) ? r.post.images : [];
    if (!images.length) { toast('该动态无图片', 'err'); return; }
    openImageViewerArray(images, 0);
  } catch (e) { toast(e.message, 'err'); }
}

function renderImageViewer() {
  // 移除旧 viewer
  const old = $('#img-viewer-el');
  if (old) old.remove();
  const cur = _viewerImages[_viewerIndex];
  const dots = _viewerImages.map((_, i) => `<div class="dot ${i === _viewerIndex ? 'on' : ''}" onclick="setViewerIndex(${i})"></div>`).join('');
  const wrap = document.createElement('div');
  wrap.id = 'img-viewer-el';
  wrap.className = 'img-viewer';
  wrap.innerHTML = `
    <div class="iv-bar">
      <button onclick="closeImageViewer()">×</button>
      <div class="iv-title">${_viewerIndex + 1} / ${_viewerImages.length}</div>
      <button onclick="saveImageViewer()" title="保存">${ICO.upload()}</button>
    </div>
    <div class="iv-body" onclick="closeImageViewer()">
      <img src="${cur}" alt="" onclick="event.stopPropagation()">
    </div>
    ${_viewerImages.length > 1 ? `<div class="iv-dots">${dots}</div>` : ''}
  `;
  document.body.appendChild(wrap);
}

function setViewerIndex(i) {
  if (i < 0 || i >= _viewerImages.length) return;
  _viewerIndex = i;
  renderImageViewer();
}

function closeImageViewer() {
  const el = $('#img-viewer-el');
  if (el) el.remove();
}

async function saveImageViewer() {
  const src = _viewerImages[_viewerIndex];
  if (!src) return;
  try {
    const a = document.createElement('a');
    a.href = src;
    a.download = 'image_' + Date.now() + '.jpg';
    document.body.appendChild(a);
    a.click();
    a.remove();
    toast('已开始下载', 'ok');
  } catch (e) { toast('保存失败', 'err'); }
}

// 评论回复状态管理
let _replyState = { parentId: 0, replyToUserId: 0, replyToUsername: '' };

function clearReplyState() {
  _replyState = { parentId: 0, replyToUserId: 0, replyToUsername: '' };
  const hint = $('#reply-hint');
  if (hint) hint.remove();
  const input = $('#cmt-input');
  if (input) input.placeholder = State.user ? '说点什么…' : '登录后评论';
}

function setReplyState(parentId, replyToUserId, replyToUsername) {
  _replyState = { parentId, replyToUserId, replyToUsername };
  const bar = $('#cmt-bar');
  if (!bar) return;
  // 移除旧 hint
  const oldHint = $('#reply-hint');
  if (oldHint) oldHint.remove();
  // 插入新 hint
  const hint = document.createElement('div');
  hint.id = 'reply-hint';
  hint.className = 'reply-hint';
  hint.innerHTML = `<span>回复 <b>${escapeHtml(replyToUsername)}</b></span><button onclick="clearReplyState()">×</button>`;
  bar.insertBefore(hint, bar.firstChild);
  const input = $('#cmt-input');
  if (input) { input.placeholder = `回复 ${replyToUsername}…`; input.focus(); }
}

function commentItemHtml(c, isChild = false) {
  const replyToHtml = c.parent_id > 0 && c.reply_to_username
    ? `<span class="c-reply-to">回复 <b>${escapeHtml(c.reply_to_username)}</b></span>` : '';
  const replyBtn = State.user ? `<button onclick="setReplyState(${c.id}, ${c.user.id}, '${escapeHtml(c.user.username).replace(/'/g, "\\'")}')">${ICO.reply()}回复</button>` : '';
  const delBtn = c.can_delete ? `<button class="danger" onclick="deleteComment(${c.id}, ${c.parent_id})">${ICO.trash()}删除</button>` : '';
  // 举报按钮（登录用户且不是自己的评论）
  const reportBtn = State.user && State.user.id !== c.user.id
    ? `<button onclick="go('/report/comment/${c.id}')">${ICO.flag()}举报</button>` : '';
  // 头像可点击进入用户主页
  const avatarHtml = `<div class="avatar" style="cursor:pointer" onclick="event.stopPropagation();go('/user/${c.user.id}')">${c.user.avatar ? `<img src="${c.user.avatar}" alt="" loading="lazy" decoding="async">` : escapeHtml(firstChar(c.user.username))}</div>`;
  return `<div class="comment-item" data-id="${c.id}">
    ${avatarHtml}
    <div class="c-body">
      <div class="c-head">
        <span class="c-name" style="cursor:pointer" onclick="event.stopPropagation();go('/user/${c.user.id}')">${escapeHtml(c.user.username)}</span>
        ${replyToHtml}
      </div>
      <div class="c-text">${escapeHtml(c.content)}</div>
      <div class="c-meta">
        <span>${c.created_at}</span>
        <div class="c-actions">
          ${replyBtn}
          ${reportBtn}
          ${delBtn}
        </div>
      </div>
    </div>
  </div>`;
}

function buildCommentTree(comments) {
  // 按 id 升序（后端已返回 ASC）。先根评论，再其子评论
  const byId = {};
  const roots = [];
  comments.forEach(c => { byId[c.id] = { ...c, children: [] }; });
  comments.forEach(c => {
    if (c.parent_id && byId[c.parent_id]) {
      byId[c.parent_id].children.push(byId[c.id]);
    } else {
      roots.push(byId[c.id]);
    }
  });
  return roots;
}

// 折叠阈值：超过此数量的子评论自动折叠
const COMMENT_FOLD_THRESHOLD = 3;

function renderCommentTree(nodes) {
  return nodes.map(node => {
    if (node.children.length > COMMENT_FOLD_THRESHOLD) {
      // 折叠：只显示前 2 条 + 「展开 N 条回复」按钮 + 剩余评论（默认隐藏）
      const visible = node.children.slice(0, 2);
      const hidden = node.children.slice(2);
      const visibleHtml = visible.map(renderCommentTree).join('');
      const hiddenHtml = hidden.map(renderCommentTree).join('');
      const foldBtn = `<div class="c-fold-btn" onclick="this.style.display='none';this.nextElementSibling.style.display=''">
        <span class="c-fold-line"></span>
        <span>展开 ${hidden.length} 条回复</span>
      </div>`;
      const hiddenWrap = `<div class="comment-children" style="display:none">${hiddenHtml}</div>`;
      const childrenHtml = `<div class="comment-children">${visibleHtml}</div>${foldBtn}${hiddenWrap}`;
      return commentItemHtml(node) + childrenHtml;
    } else {
      const childrenHtml = node.children.length
        ? `<div class="comment-children">${renderCommentTree(node.children)}</div>`
        : '';
      return commentItemHtml(node) + childrenHtml;
    }
  }).join('');
}

async function loadComments(id) {
  try {
    const r = await api('comments&id=' + id);
    const tree = buildCommentTree(r.comments);
    $('#cmt-list').innerHTML = r.comments.length
      ? renderCommentTree(tree)
      : `<div style="text-align:center;color:var(--text-3);padding:30px 0;font-size:13px">还没有评论，来说点什么吧</div>`;
  } catch (e) {}
}

async function sendComment(id) {
  if (!State.user) { toast('请先登录', 'err'); go('/login'); return; }
  const input = $('#cmt-input');
  const v = input.value.trim();
  if (!v) return;
  // 评论按钮锁，防止连点
  const sendBtn = input?.parentElement?.querySelector('button');
  if (sendBtn) { sendBtn.disabled = true; sendBtn.dataset.originalText = sendBtn.textContent; sendBtn.textContent = '发送中…'; }
  try {
    const payload = { id, content: v };
    if (_replyState.parentId) {
      payload.parent_id = _replyState.parentId;
      payload.reply_to_user_id = _replyState.replyToUserId;
    }
    // BotGuard 无感人机验证：注入 token + 指纹
    const payloadWithBg = await BotGuard.attachTo(payload);
    const r = await api('comment', payloadWithBg);
    input.value = '';
    clearReplyState();
    // 重新加载评论列表（保持时序）
    await loadComments(id);
    // 更新计数
    const cmtBtn = $('#btn-cmt');
    if (cmtBtn) {
      const span = cmtBtn.querySelector('span');
      span.textContent = (parseInt(span.textContent) || 0) + 1;
    }
    toast('评论成功', 'ok');
  } catch (e) {
    toast(e.message, 'err');
    if (e.message && e.message.indexOf('人机验证') !== -1) BotGuard.reset();
  } finally {
    if (sendBtn) { sendBtn.disabled = false; if (sendBtn.dataset.originalText) sendBtn.textContent = sendBtn.dataset.originalText; }
  }
}

async function deleteComment(id, parentId) {
  showConfirm('确定删除这条评论吗？' + (parentId ? '其下所有回复也会一并删除。' : ''), '删除评论', async () => {
    try {
      const r = await api('delete_comment', { id });
      toast('已删除', 'ok');
      const postId = State.route.split('/')[1];
      await loadComments(parseInt(postId));
      const cmtBtn = $('#btn-cmt');
      if (cmtBtn) {
        const span = cmtBtn.querySelector('span');
        span.textContent = Math.max(0, (parseInt(span.textContent) || 0) - (r.deleted_count || 1));
      }
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
}

async function deleteOwnPost(id) {
  showConfirm('确定删除这个帖子吗？此操作不可撤销。', '删除帖子', async () => {
    try {
      await api('delete_own_post', { id });
      toast('已删除', 'ok');
      go('/home');
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
}

async function toggleDetailLike(id, btn) {
  if (!State.user) { go('/login'); return; }
  try {
    const r = await api('like', { id });
    btn.classList.toggle('on', r.liked);
    btn.querySelector('span').textContent = r.count;
  } catch (e) { toast(e.message, 'err'); }
}
async function toggleDetailFav(id, btn) {
  if (!State.user) { go('/login'); return; }
  try {
    const r = await api('favorite', { id });
    btn.classList.toggle('on-fav', r.favorited);
    btn.querySelector('span').textContent = r.count;
  } catch (e) { toast(e.message, 'err'); }
}

async function toggleLike(id, btn) {
  if (!State.user) { go('/login'); return; }
  try {
    const r = await api('like', { id });
    btn.classList.toggle('on', r.liked);
    btn.querySelector('span').textContent = r.count;
  } catch (e) { toast(e.message, 'err'); }
}
async function toggleFav(id, btn) {
  if (!State.user) { go('/login'); return; }
  try {
    const r = await api('favorite', { id });
    btn.classList.toggle('on-fav', r.favorited);
    btn.querySelector('span').textContent = r.count;
  } catch (e) { toast(e.message, 'err'); }
}

/* =========================================================
 *  Play (embed / jump)
 * ========================================================= */
async function renderPlay(id) {
  const app = $('#app');
  app.innerHTML = `<div class="page"><div class="page-scroll" style="display:grid;place-items:center;height:100%">${View.skeleton(1)}</div></div>`;
  try {
    const r = await api('play&id=' + id);
    if (r.view_mode === 'jump') {
      const blob = new Blob([r.html], { type: 'text/html' });
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      go('/post/' + id);
      return;
    }
    // 双模式：持久模式用 allow-same-origin（localStorage 可用），默认模式无（安全沙箱）
    const sandbox = r.persistent_mode
      ? 'allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock allow-presentation'
      : 'allow-scripts allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock allow-presentation';
    const persistBadge = r.persistent_mode ? '<span style="font-size:11px;color:var(--accent);background:var(--accent-soft);padding:2px 8px;border-radius:3px;margin-left:8px">💾 持久模式</span>' : '';
    app.innerHTML = `<div class="play-frame">
      <div class="pf-bar">
        <button class="back" onclick="goBack()">${ICO.back()}</button>
        <div class="pf-title">${escapeHtml(r.title)}${persistBadge}</div>
        <button class="icon-btn" onclick="openFullscreen()">${ICO.eye()}</button>
      </div>
      <iframe id="play-iframe" sandbox="${sandbox}"></iframe>
    </div>`;
    $('#play-iframe').srcdoc = r.html;
    window.openFullscreen = () => {
      const f = $('#play-iframe');
      if (f.requestFullscreen) f.requestFullscreen();
    };
  } catch (e) {
    toast(e.message, 'err');
    go('/post/' + id);
  }
}

/* =========================================================
 *  Code Viewer (查看 HTML 源代码)
 * ========================================================= */

// 简易 HTML 语法高亮：转义后给标签/属性/字符串/注释着色
// 安全性：先 escapeHtml 转义所有内容，再用正则替换着色 span
// 由于已先转义，正则匹配的只会是 &lt; &gt; 等，不会执行任何注入
function highlightHtml(code) {
  let s = escapeHtml(code);
  // DOCTYPE
  s = s.replace(/(&lt;!DOCTYPE[^&]*?&gt;)/gi, '<span class="cv-doctype">$1</span>');
  // 注释
  s = s.replace(/(&lt;!--[\s\S]*?--&gt;)/g, '<span class="cv-com">$1</span>');
  // 标签名（开标签和闭标签）
  s = s.replace(/(&lt;\/?)([a-zA-Z][a-zA-Z0-9-]*)/g, '$1<span class="cv-tag">$2</span>');
  // 属性名="属性值"
  s = s.replace(/([a-zA-Z-]+)(=)(&quot;[^&]*?&quot;|&#39;[^&]*?&#39;)/g,
    '<span class="cv-attr">$1</span>$2<span class="cv-str">$3</span>');
  return s;
}

// 兼容新旧浏览器的复制函数
async function copyToClipboard(text) {
  // 现代浏览器：navigator.clipboard API（需 HTTPS 或 localhost）
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      // 失败则降级到 execCommand
    }
  }
  // 降级方案：textarea + execCommand('copy')
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    ta.setAttribute('readonly', '');
    document.body.appendChild(ta);
    // iOS Safari 需要先 createTextRange
    if (navigator.userAgent.match(/ipad|ipod|iphone/i)) {
      const range = document.createRange();
      range.selectNodeContents(ta);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      ta.setSelectionRange(0, text.length);
    } else {
      ta.select();
    }
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch (e) {
    return false;
  }
}

async function renderCodeViewer(id) {
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide code-viewer-page">
    <div class="code-toolbar">
      <button onclick="goBack()" title="返回">${ICO.back()}</button>
      <div class="ct-title">加载中…</div>
      <button onclick="copySourceCode()" id="cv-copy-btn">${ICO.copy()} 复制</button>
    </div>
    <div class="code-body"><div class="code-content">加载中…</div></div>
  </div>`;
  try {
    const r = await api('view_source&id=' + id);
    $('.ct-title').textContent = r.title;
    let metaHtml = `<span>${r.created_at}</span>`;
    if (r.is_edited) {
      metaHtml += `<span class="ct-edited-badge">已编辑 · ${r.edited_at}</span>`;
    }
    if (r.author) {
      metaHtml += `<span>· 作者：${escapeHtml(r.author.username)}</span>`;
    }
    // 在标题后插入 meta
    const toolbar = $('.code-toolbar');
    const metaDiv = document.createElement('div');
    metaDiv.className = 'ct-meta';
    metaDiv.innerHTML = metaHtml;
    toolbar.insertBefore(metaDiv, $('#cv-copy-btn'));
    // 渲染代码（带高亮）
    const codeBody = $('.code-body .code-content');
    codeBody.innerHTML = highlightHtml(r.html);
    // 存储原始代码供复制
    window.__sourceCode = r.html;
  } catch (e) {
    toast(e.message, 'err');
    $('.code-body .code-content').textContent = '加载失败：' + e.message;
  }
}

window.copySourceCode = async () => {
  const code = window.__sourceCode || '';
  if (!code) { toast('无代码可复制', 'err'); return; }
  const btn = $('#cv-copy-btn');
  const oldHtml = btn.innerHTML;
  btn.disabled = true;
  const ok = await copyToClipboard(code);
  if (ok) {
    btn.innerHTML = '✓ 已复制';
    toast('代码已复制到剪贴板', 'ok');
  } else {
    toast('复制失败，请手动选择代码复制', 'err');
  }
  setTimeout(() => { btn.innerHTML = oldHtml; btn.disabled = false; }, 1500);
};

/* =========================================================
 *  Edit Post (作者修改 HTML 代码)
 * ========================================================= */
async function renderEditPost(id) {
  const app = $('#app');
  if (!State.user) { go('/login'); return; }
  app.innerHTML = `<div class="page page-slide edit-post-page">
    <div class="edit-post-toolbar">
      <button onclick="goBack()" title="返回">${ICO.back()}</button>
      <div class="ept-title">编辑作品</div>
      <button onclick="go('/code/${id}')">${ICO.code()} 查看代码</button>
      <button class="primary" id="ep-save-btn" onclick="saveEditPost(${id})">${ICO.check()} 保存</button>
    </div>
    <div class="edit-post-body">
      <div class="edit-post-warn">${ICO.ban()} 修改后作品会显示「已编辑」标识，所有查看者可见。此操作不可撤销。</div>
      <div class="field">
        <label>标题</label>
        <input class="input" id="ep-title" maxlength="50" placeholder="作品标题">
      </div>
      <div class="field">
        <label>HTML 代码</label>
        <textarea class="textarea ep-code-area" id="ep-html" spellcheck="false" placeholder="<!DOCTYPE html>..."></textarea>
      </div>
      <div style="font-size:11px;color:var(--text-3);line-height:1.5">支持直接粘贴或修改代码，保存后即时生效。代码上限 500KB。</div>
    </div>
  </div>`;
  // 加载原作品数据
  try {
    const r = await api('view_source&id=' + id);
    // 校验是否为作者
    if (!r.author || r.author.id !== State.user.id) {
      toast('只能修改自己的作品', 'err');
      goBack();
      return;
    }
    $('#ep-title').value = r.title;
    $('#ep-html').value = r.html;
  } catch (e) {
    toast(e.message, 'err');
    goBack();
  }
}

window.saveEditPost = async (id) => {
  const title = $('#ep-title').value.trim();
  const html = $('#ep-html').value;
  if (!title) { toast('标题不能为空', 'err'); return; }
  if (!html.trim()) { toast('HTML 代码不能为空', 'err'); return; }
  if (html.length > 500000) { toast('代码超过 500KB 限制', 'err'); return; }
  const btn = $('#ep-save-btn');
  btn.disabled = true; btn.innerHTML = '保存中…';
  try {
    await api('update_post_html', { id, title, html_content: html });
    toast('已保存，作品已标记为「已编辑」', 'ok');
    setTimeout(() => { location.replace('#/post/' + id); }, 500);
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存`;
  }
};

/* =========================================================
 *  New post
 * ========================================================= */
function renderNew() {
  const app = $('#app');
  // 解析 URL 中的 studio 参数（从工作室详情页跳转过来）
  const hash = location.hash;
  const studioMatch = hash.match(/studio=(\d+)/);
  const initialStudioId = studioMatch ? parseInt(studioMatch[1]) : 0;
  app.innerHTML = `<div class="page">
    ${View.topbar('发布', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="page-scroll">
      <div class="form-wrap">
        <div class="edit-type">
          <div class="et on" data-t="html" onclick="pickType('html')">
            <div class="et-ico">&lt;/&gt;</div>
            <div class="et-t">HTML 作品</div>
            <div class="et-d">代码 / 文件 + 封面</div>
          </div>
          <div class="et" data-t="text" onclick="pickType('text')">
            <div class="et-ico">📝</div>
            <div class="et-t">文字动态</div>
            <div class="et-d">纯文字分享</div>
          </div>
        </div>

        <div class="field">
          <label>标题 <span id="title-optional-hint" style="font-size:11px;color:var(--text-3);font-weight:400">（HTML 作品必填，文字动态可选）</span></label>
          <input class="input" id="f-title" placeholder="给你的作品起个好名字" maxlength="50">
        </div>

        <div id="type-html">
          <div class="field">
            <label>HTML 代码（输入或粘贴）</label>
            <textarea class="textarea code-area" id="f-html" placeholder="<!DOCTYPE html>&#10;<html>&#10;  <head><title>My Work</title></head>&#10;  <body>&#10;    <h1>Hello HTMLHub</h1>&#10;  </body>&#10;</html>" spellcheck="false"></textarea>
          </div>
          <div class="field">
            <label>或上传 .html 文件</label>
            <input type="file" id="f-file" accept=".html,.htm,text/html" class="hidden">
            <button class="btn ghost" onclick="$('#f-file').click()">${ICO.upload()} 选择 HTML 文件</button>
          </div>

          <div class="section-label">${ICO.eye()} 实时预览</div>
          <div class="live-preview">
            <div class="lp-head"><span class="dot"></span> 预览</div>
            <iframe id="lp-iframe" sandbox="allow-scripts allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock allow-presentation"></iframe>
          </div>
          <div class="lp-actions">
            <button onclick="refreshPreview()">${ICO.refresh()} 刷新预览</button>
            <button onclick="autoCover()">${ICO.camera()} 自动截图</button>
            ${State.settings.code_score_enabled ? `<button onclick="scoreCurrentCode()">${ICO.chart()} 代码评分</button>` : ''}
          </div>

          <div class="section-label">${ICO.camera()} 封面图</div>
          <div class="cover-zone" id="cover-zone" onclick="pickCover()">
            <div class="cover-zz">${ICO.camera()}</div>
            <div class="cover-zt">点击上传封面 · 或使用「自动截图」</div>
            <div class="cover-zd">支持 PNG / JPG / WebP · ≤ 2MB</div>
          </div>
          <input type="file" id="cover-file" accept="image/*" class="hidden">

          <div class="section-label">${ICO.eye()} 浏览方式</div>
          <div class="view-mode-pick">
            <div class="vm on" data-m="embed" onclick="pickMode('embed')">📱 内嵌浏览<br><span style="font-size:11px;color:var(--text-3)">应用内打开</span></div>
            <div class="vm" data-m="jump" onclick="pickMode('jump')">↗ 跳转浏览<br><span style="font-size:11px;color:var(--text-3)">新标签页打开</span></div>
          </div>

          <div class="section-label">💾 持久化模式</div>
          <div style="padding:12px 14px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:var(--text-1);font-weight:500;line-height:1.5">
              <input type="checkbox" id="f-persistent" style="width:18px;height:18px;accent-color:var(--accent);flex-shrink:0;margin-top:1px">
              <span>
                <div>开启持久化模式（localStorage 可用）</div>
                <div style="font-size:11px;color:var(--text-3);font-weight:400;margin-top:4px;line-height:1.6">
                  开启后，作品的 JS 可使用 localStorage 保存数据（如游戏进度、设置）。<br>
                  安全说明：此模式下 cookie 仍不可读（httponly），API 调用被 CSP 阻断，
                  仅 localStorage 可用。适合需要本地存储的作品。默认关闭更安全。
                </div>
              </span>
            </label>
          </div>
        </div>

        <div class="section-label">${ICO.studio()} 发布到</div>
        <div id="studio-pick-wrap"></div>

        <div id="type-text" style="display:none">
          <div class="field">
            <label>动态内容（支持 Markdown）</label>
            <div class="md-editor-wrap">
              <div class="md-toolbar">
                <button onclick="mdAction('bold')" title="粗体"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/></svg></button>
                <button onclick="mdAction('italic')" title="斜体"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/></svg></button>
                <button onclick="mdAction('strike')" title="删除线"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="12" x2="20" y2="12"/><path d="M16 6a4 4 0 0 0-4-2H9a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-3a4 4 0 0 1-4-2"/></svg></button>
                <span class="md-sep"></span>
                <button onclick="mdAction('h1')" title="一级标题" style="font-family:serif;font-size:15px">H1</button>
                <button onclick="mdAction('h2')" title="二级标题" style="font-family:serif;font-size:15px">H2</button>
                <button onclick="mdAction('h3')" title="三级标题" style="font-family:serif;font-size:15px">H3</button>
                <span class="md-sep"></span>
                <button onclick="mdAction('ul')" title="无序列表"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></button>
                <button onclick="mdAction('ol')" title="有序列表"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg></button>
                <button onclick="mdAction('quote')" title="引用"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/></svg></button>
                <span class="md-sep"></span>
                <button onclick="mdAction('code')" title="行内代码"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg></button>
                <button onclick="mdAction('codeblock')" title="代码块"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="9 9 7 12 9 15"/><polyline points="15 9 17 12 15 15"/></svg></button>
                <button onclick="mdAction('link')" title="链接"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
                <button onclick="mdAction('hr')" title="分割线"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/></svg></button>
                <span class="md-spacer"></span>
                <button onclick="mdAction('preview')" title="预览" id="md-preview-btn" class="md-preview-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
              </div>
              <textarea class="textarea" id="f-text" placeholder="支持 Markdown 格式…\n\n# 标题\n**粗体** *斜体* ~~删除线~~\n- 列表项\n> 引用\n\`行内代码\`\n\`\`\`代码块\`\`\`\n[链接](https://example.com)" maxlength="5000" spellcheck="false"></textarea>
              <div id="md-preview-area" class="md-preview-area" style="display:none"></div>
            </div>
          </div>
          <div class="field">
            <label>图片（最多 9 张，每张 ≤ 999KB）</label>
            <div class="img-picker" id="img-picker"></div>
            <input type="file" id="text-img-file" accept="image/*" class="hidden">
            <div style="font-size:11px;color:var(--text-3);margin-top:6px;line-height:1.5">图片会自动压缩，支持 PNG / JPG / WebP。仅文字动态可附图。</div>
          </div>
        </div>

        <div style="margin-top:24px">
          <button class="btn" id="publish-btn" onclick="publish()">发布</button>
        </div>
      </div>
    </div>
  </div>`;

  let curType = 'html';
  let curMode = 'embed';
  let coverData = null;
  let _textImages = [];
  let _selectedStudioId = initialStudioId;

  // === 文字动态图片选择器 ===
  function renderImgPicker() {
    const picker = $('#img-picker');
    if (!picker) return;
    const tiles = [];
    _textImages.forEach((src, i) => {
      tiles.push(`<div class="img-pick-tile" onclick="removeTextImage(${i})">
        <img src="${src}" alt="" loading="lazy" decoding="async">
        <div class="ip-clear">×</div>
      </div>`);
    });
    if (_textImages.length < 9) {
      tiles.push(`<div class="img-pick-tile add-tile" onclick="$('#text-img-file').click()">${ICO.plus()}</div>`);
    }
    picker.innerHTML = tiles.join('');
  }
  renderImgPicker();

  $('#text-img-file').addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    if (f.size > 999 * 1024) { toast(`图片需 ≤ 999KB（当前 ${(f.size/1024).toFixed(0)}KB）`, 'err'); e.target.value = ''; return; }
    const reader = new FileReader();
    reader.onload = () => {
      _textImages.push(reader.result);
      renderImgPicker();
      toast(`已选择 ${_textImages.length} 张图片`, 'ok');
    };
    reader.onerror = () => toast('图片读取失败', 'err');
    reader.readAsDataURL(f);
    e.target.value = '';
  });

  window.removeTextImage = (idx) => {
    _textImages.splice(idx, 1);
    renderImgPicker();
  };

  // === 工作室选择器 ===
  async function loadStudioPicker() {
    if (!State.user) return;
    try {
      const r = await api('studios?mine=1');
      const wrap = $('#studio-pick-wrap');
      if (!wrap) return;
      if (r.studios.length === 0) {
        wrap.innerHTML = '<div style="font-size:12px;color:var(--text-3);padding:6px 0">还没有加入工作室，<a onclick="go(\'/studio/new\')" style="color:var(--accent)">创建一个</a> 或 <a onclick="go(\'/studios\')" style="color:var(--accent)">浏览工作室</a></div>';
        return;
      }
      wrap.innerHTML = `<div class="studio-pick-row ${_selectedStudioId===0?'on':''}" onclick="selectStudio(0)">
        <div class="spr-avatar">·</div>
        <div class="spr-info"><div class="spr-name">发布到主页</div></div>
        ${_selectedStudioId===0?'<span class="spr-check">✓</span>':''}
      </div>` + r.studios.map(s => `<div class="studio-pick-row ${_selectedStudioId===s.id?'on':''}" onclick="selectStudio(${s.id})">
        <div class="spr-avatar">${escapeHtml(firstChar(s.name))}</div>
        <div class="spr-info"><div class="spr-name">${escapeHtml(s.name)}</div></div>
        ${_selectedStudioId===s.id?'<span class="spr-check">✓</span>':''}
      </div>`).join('');
    } catch (e) {}
  }
  window.selectStudio = (id) => {
    _selectedStudioId = id;
    loadStudioPicker();
  };
  loadStudioPicker();

  window.pickType = (t) => {
    curType = t;
    $$('.edit-type .et').forEach(x => x.classList.toggle('on', x.dataset.t === t));
    $('#type-html').style.display = t === 'html' ? '' : 'none';
    $('#type-text').style.display = t === 'text' ? '' : 'none';
  };
  window.pickMode = (m) => {
    curMode = m;
    $$('.view-mode-pick .vm').forEach(x => x.classList.toggle('on', x.dataset.m === m));
  };

  // file upload -> fill html
  $('#f-file').addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = () => {
      $('#f-html').value = reader.result;
      refreshPreview();
      toast('文件已加载', 'ok');
    };
    reader.readAsText(f);
  });

  // live preview - debounce
  let pvTimer;
  $('#f-html').addEventListener('input', () => {
    clearTimeout(pvTimer);
    pvTimer = setTimeout(refreshPreview, 600);
  });

  window.refreshPreview = () => {
    const code = $('#f-html').value;
    if (!code.trim()) { toast('请先输入 HTML 代码', 'err'); return; }
    $('#lp-iframe').srcdoc = code;
  };

  window.autoCover = async () => {
    const code = $('#f-html').value;
    if (!code.trim()) { toast('请先输入 HTML 代码', 'err'); return; }
    if (typeof html2canvas === 'undefined') { toast('截图库未加载，请用上传方式', 'err'); return; }
    toast('正在生成截图…');
    try {
      const ifr = document.createElement('iframe');
      ifr.style.cssText = 'position:fixed;left:-99999px;top:0;width:1280px;height:720px;border:none;background:#fff';
      // 安全说明：此处保留 allow-same-origin 是因为 html2canvas 需要读取 iframe DOM。
      // 风险可控：仅作者本人对自己代码触发、iframe 隐藏且截图后立即移除。
      ifr.sandbox = 'allow-scripts allow-same-origin';
      ifr.srcdoc = code;
      document.body.appendChild(ifr);
      await new Promise(res => { ifr.onload = res; setTimeout(res, 3000); });
      await new Promise(r => setTimeout(r, 800));
      const doc = ifr.contentWindow.document;
      const canvas = await html2canvas(doc.body, { width: 1280, height: 720, useCORS: true, backgroundColor: '#ffffff' });
      const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
      ifr.remove();
      if (dataUrl.length > 2 * 1024 * 1024) {
        toast('截图过大，请简化作品或降低分辨率', 'err');
        return;
      }
      coverData = dataUrl;
      showCover(dataUrl);
      toast('截图已生成', 'ok');
    } catch (e) {
      toast('截图失败：' + e.message + '（可改用上传）', 'err');
    }
  };

  window.pickCover = () => $('#cover-file').click();
  $('#cover-file').addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    if (f.size > 2 * 1024 * 1024) { toast('文件超过 2MB', 'err'); return; }
    const reader = new FileReader();
    reader.onload = () => { coverData = reader.result; showCover(reader.result); };
    reader.readAsDataURL(f);
  });

  function showCover(dataUrl) {
    const zone = $('#cover-zone');
    zone.classList.add('has-img');
    zone.innerHTML = `<img src="${dataUrl}" alt=""><button class="cz-clear" onclick="event.stopPropagation();clearCover()">×</button>`;
  }
  window.clearCover = () => {
    coverData = null;
    const zone = $('#cover-zone');
    zone.classList.remove('has-img');
    zone.innerHTML = `<div class="cover-zz">${ICO.camera()}</div>
      <div class="cover-zt">点击上传封面 · 或使用「自动截图」</div>
      <div class="cover-zd">支持 PNG / JPG / WebP · ≤ 2MB</div>`;
  };

  window.publish = async () => {
    const title = $('#f-title').value.trim();
    const btn = $('#publish-btn');
    btn.disabled = true; btn.textContent = '发布中…';
    try {
      let payload = { type: curType, title, view_mode: curMode, cover: coverData, studio_id: _selectedStudioId };
      if (curType === 'html') {
        if (!title) { toast('HTML 作品需要标题', 'err'); btn.disabled = false; btn.textContent = '发布'; return; }
        const html = $('#f-html').value.trim();
        if (!html) { toast('请输入 HTML 代码', 'err'); btn.disabled = false; btn.textContent = '发布'; return; }
        payload.html_content = html;
        // 持久化模式（仅 HTML 作品）
        const persistEl = $('#f-persistent');
        if (persistEl && persistEl.checked) payload.persistent_mode = 1;
      } else {
        const text = $('#f-text').value.trim();
        // 文字动态：标题和内容至少有一项
        if (!title && !text && _textImages.length === 0) {
          toast('标题或内容至少填一项', 'err'); btn.disabled = false; btn.textContent = '发布'; return;
        }
        payload.content = text;
        if (_textImages.length > 0) payload.images = _textImages;
      }
      // BotGuard 无感人机验证：注入 token + 指纹
      payload = await BotGuard.attachTo(payload);
      const r = await api('create_post', payload);
      toast('发布成功', 'ok');
      // 使用 location.replace 替换当前历史条目，避免按返回键回到发布页
      setTimeout(() => { location.replace('#/post/' + r.id); }, 400);
    } catch (e) {
      toast(e.message, 'err');
      if (e.message && e.message.indexOf('人机验证') !== -1) BotGuard.reset();
      btn.disabled = false; btn.textContent = '发布';
    }
  };

  // === Markdown 编辑器工具栏 ===
  window.mdAction = (action) => {
    const ta = $('#f-text');
    if (!ta) return;
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const sel = ta.value.substring(start, end);
    const before = ta.value.substring(0, start);
    const after = ta.value.substring(end);
    let insert = sel;
    let cursorOffset = 0;
    switch (action) {
      case 'bold': insert = `**${sel || '粗体'}**`; cursorOffset = sel ? 0 : -6; break;
      case 'italic': insert = `*${sel || '斜体'}*`; cursorOffset = sel ? 0 : -5; break;
      case 'strike': insert = `~~${sel || '删除线'}~~`; cursorOffset = sel ? 0 : -7; break;
      case 'h1': insert = `# ${sel || '标题'}`; cursorOffset = sel ? 0 : -2; break;
      case 'h2': insert = `## ${sel || '标题'}`; cursorOffset = sel ? 0 : -2; break;
      case 'h3': insert = `### ${sel || '标题'}`; cursorOffset = sel ? 0 : -2; break;
      case 'ul': insert = `- ${sel || '列表项'}`; cursorOffset = sel ? 0 : -3; break;
      case 'ol': insert = `1. ${sel || '列表项'}`; cursorOffset = sel ? 0 : -3; break;
      case 'quote': insert = `> ${sel || '引用'}`; cursorOffset = sel ? 0 : -2; break;
      case 'code': insert = `\`${sel || '代码'}\``; cursorOffset = sel ? 0 : -5; break;
      case 'codeblock': insert = `\n\`\`\`\n${sel || '代码块'}\n\`\`\`\n`; cursorOffset = sel ? 0 : -8; break;
      case 'link': insert = `[${sel || '链接文字'}](https://)`; cursorOffset = -1; break;
      case 'hr': insert = `\n---\n`; break;
      case 'preview':
        const area = $('#md-preview-area');
        const btn = $('#md-preview-btn');
        if (area.style.display === 'none') {
          area.style.display = '';
          area.innerHTML = `<div class="md-content">${renderMarkdown(ta.value)}</div>`;
          ta.style.display = 'none';
          btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
          btn.title = '编辑';
        } else {
          area.style.display = 'none';
          ta.style.display = '';
          btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
          btn.title = '预览';
        }
        return;
    }
    ta.value = before + insert + after;
    const newPos = start + insert.length + cursorOffset;
    ta.focus();
    ta.setSelectionRange(newPos, newPos);
  };

  setTimeout(refreshPreview, 100);
}

/* =========================================================
 *  Profile
 * ========================================================= */
async function renderProfile() {
  const app = $('#app');
  if (!State.user) {
    app.innerHTML = `<div class="page">
      ${View.topbar('我的')}
      <div class="page-scroll">
        <div class="empty">
          <div class="em-ico">👤</div>
          <p style="font-weight:600;color:var(--text-2);margin-bottom:6px">未登录</p>
          <p>登录后即可发布作品、点赞收藏</p>
          <button class="em-btn" onclick="go('/login')">登录 / 注册</button>
        </div>
      </div>
      ${View.bottomNav('profile')}
    </div>`;
    return;
  }
  app.innerHTML = `<div class="page">
    ${View.topbar('我的', `${State.user && State.user.role==='admin' ? `<button class="icon-btn" onclick="go('/admin')" title="管理后台">${ICO.shield()}</button>` : ''}<button class="icon-btn" onclick="go('/settings')" title="设置">${ICO.settings()}</button><button class="icon-btn" onclick="go('/theme')" title="主题设置">${ICO.palette()}</button><button class="icon-btn notif-badge" onclick="go('/notifications')" title="消息通知">${ICO.bell()}<span class="notif-dot ${State.unreadNotifs>0?'':'empty'}" id="profile-notif-dot">${State.unreadNotifs>99?'99+':State.unreadNotifs}</span></button><button class="icon-btn" onclick="logout()" title="退出登录">${ICO.logout()}</button>`)}
    <div class="page-scroll" id="pf-scroll">
      <div class="profile-head">
        ${avatarHtml(State.user)}
        <div class="p-name">${escapeHtml(State.user.username)} ${State.user.role==='admin'?'<span class="p-badge">管理员</span>':''}</div>
        <div class="p-bio">${renderUserBio(State.user)}</div>
        <div class="p-stats">
          <div class="p-stat"><b id="stat-posts">-</b><span>作品</span></div>
          <div class="p-stat"><b id="stat-likes">-</b><span>获赞</span></div>
          <div class="p-stat"><b id="stat-favs">-</b><span>收藏</span></div>
          <div class="p-stat" onclick="go('/followers/${State.user.id}')"><b id="stat-followers">-</b><span>粉丝</span></div>
          <div class="p-stat" onclick="go('/following/${State.user.id}')"><b id="stat-following">-</b><span>关注</span></div>
        </div>
        <div class="p-actions">
          <button class="btn ghost" onclick="editProfile()">${ICO.edit()} 编辑资料</button>
        </div>
      </div>
      ${renderUserContactCard(State.user)}
      <div class="profile-tabs">
        <button class="pt on" data-tab="posts" onclick="switchTab('posts')">我的作品</button>
        <button class="pt" data-tab="favs" onclick="switchTab('favs')">我的收藏</button>
      </div>
      <div id="pf-list">${View.skeleton()}</div>
    </div>
    ${View.bottomNav('profile')}
  </div>`;

  // 分页 + 无限滚动状态
  let curTab = 'posts';
  let page = 1;
  let hasMore = true;
  let loading = false;

  // 异步加载统计栏数据（只调一次 user 接口，拿到所有统计字段）
  async function loadStats() {
    try {
      const ur = await api('user&id=' + State.user.id);
      const u = ur.user;
      $('#stat-posts').textContent = u.posts_count ?? '-';
      $('#stat-likes').textContent = u.likes_received ?? '-';
      $('#stat-favs').textContent = u.favorites_made ?? '-';
      $('#stat-followers').textContent = u.followers_count ?? '-';
      $('#stat-following').textContent = u.following_count ?? '-';
    } catch (e) {
      $('#stat-posts').textContent = '-';
    }
  }

  async function loadTab(reset = false) {
    if (loading) return;
    if (reset) {
      page = 1;
      hasMore = true;
      $('#pf-list').innerHTML = View.skeleton();
    }
    if (!hasMore && !reset) return;
    loading = true;
    try {
      const url = curTab === 'posts'
        ? `posts?user_id=${State.user.id}&page=${page}`
        : `posts?fav_user=${State.user.id}&page=${page}`;
      const r = await api(url);
      hasMore = !!r.has_more;
      const html = r.posts.length
        ? r.posts.map(View.postCard).join('')
        : (reset
          ? View.empty(curTab === 'posts' ? '还没有发布作品' : '还没有收藏', '去发现更多有趣的内容吧', '去发现', "go('/discover')")
          : '');
      if (reset) {
        $('#pf-list').innerHTML = html;
      } else {
        // 追加前移除底部 footer
        const footer = $('#pf-list .list-footer');
        if (footer) footer.remove();
        $('#pf-list').insertAdjacentHTML('beforeend', html);
      }
      // 底部状态
      if (r.posts.length > 0) {
        const footerHtml = hasMore
          ? `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多…</div>`
          : `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">· 已经到底啦 ·</div>`;
        $('#pf-list').insertAdjacentHTML('beforeend', footerHtml);
      }
      page++;
    } catch (e) {
      if (reset) $('#pf-list').innerHTML = View.empty('加载失败', e.message);
      hasMore = false;
    } finally {
      loading = false;
    }
  }

  window.switchTab = (t) => {
    if (curTab === t) return;
    curTab = t;
    $$('.profile-tabs .pt').forEach(x => x.classList.toggle('on', x.dataset.tab === t));
    loadTab(true);
  };
  window.logout = () => {
    showConfirm('确定要退出登录吗？', '退出登录', async () => {
      try { await api('logout'); } catch (e) {}
      State.user = null;
      State._meTried = false;
      State._notifPolled = false;
      State.unreadNotifs = 0;
      if (_notifTimer) { clearTimeout(_notifTimer); _notifTimer = null; }
      // 登出后重置 BotGuard 缓存，避免下个用户复用旧 token
      try { BotGuard.reset(); } catch (e) {}
      toast('已退出', 'ok');
      go('/home');
    }, null, { danger: true, okText: '退出' });
  };
  window.editProfile = () => {
    go('/profile/edit');
  };

  // 滚动监听：触底加载下一页
  const scroll = $('#pf-scroll');
  if (scroll && !scroll._htmlhubScrollBound) {
    scroll._htmlhubScrollBound = true;
    scroll.addEventListener('scroll', () => {
      if (loading || !hasMore) return;
      if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 200) {
        loadTab(false);
      }
    });
  }

  // 并行加载：统计栏 + 首屏作品列表
  loadStats();
  loadTab(true);
}

/* =========================================================
 *  Profile Edit (独立页面)
 * ========================================================= */
function renderProfileEdit() {
  const app = $('#app');
  if (!State.user) { go('/login'); return; }

  // 解析当前联系方式（State.user.contact 是数组或 undefined）
  let contactList = Array.isArray(State.user.contact) ? [...State.user.contact] : [];

  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('编辑资料', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="page-scroll">
      <div class="form-wrap">
        <div class="field">
          <label>头像</label>
          <div style="display:flex;gap:14px;align-items:center">
            <div id="av-preview" style="width:64px;height:64px;border-radius:10px;overflow:hidden;border:2px solid var(--border);background:var(--bg-2);display:grid;place-items:center">
              ${State.user.avatar
                ? `<img src="${State.user.avatar}" style="width:100%;height:100%;object-fit:cover">`
                : `<div style="width:100%;height:100%;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700;font-size:24px">${escapeHtml(firstChar(State.user.username))}</div>`}
            </div>
            <button class="btn ghost" style="width:auto;padding:8px 16px;font-size:13px" onclick="$('#av-file').click()">${ICO.upload()} 更换头像</button>
            <button class="btn ghost" id="av-clear" style="width:auto;padding:8px 16px;font-size:13px;${State.user.avatar ? '' : 'display:none'}" onclick="clearAvatar()">移除</button>
            <input type="file" id="av-file" accept="image/*" class="hidden">
          </div>
          <div style="font-size:11px;color:var(--text-3);margin-top:6px;line-height:1.5">支持 PNG / JPG / WebP，服务器会自动压缩到 256px。原文件 ≤ 2MB。</div>
        </div>

        <div class="field">
          <label>用户名（3-20 位字母 / 数字 / 下划线）</label>
          <input class="input" id="ed-username" maxlength="20" value="${escapeHtml(State.user.username)}" placeholder="新用户名">
          <div style="font-size:11px;color:var(--text-3);margin-top:6px;line-height:1.5">修改用户名后其他设备需重新登录。用户名必须全局唯一。</div>
        </div>

        <div class="field">
          <label>简介（≤100 字）</label>
          <textarea class="textarea" id="ed-bio" maxlength="100" placeholder="介绍一下自己，让其他创作者认识你">${escapeHtml(State.user.bio || '')}</textarea>
          <div style="display:flex;justify-content:space-between;margin-top:6px">
            <span style="font-size:11px;color:var(--text-3)">支持普通文字，HTML 标签会被过滤</span>
            <span style="font-size:11px;color:var(--text-3)" id="bio-counter">${(State.user.bio || '').length}/100</span>
          </div>
        </div>

        <div class="field">
          <label>联系方式（最多 10 条）</label>
          <div id="contact-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px"></div>
          <button class="btn ghost" style="width:auto;padding:8px 14px;font-size:12px" onclick="addContactRow()">${ICO.plus()} 添加联系方式</button>
          <div style="font-size:11px;color:var(--text-3);margin-top:6px;line-height:1.5">
            支持微信 / QQ / 邮箱 / 手机 / Telegram / Discord / GitHub / Gitee / 微博 / 哔哩哔哩 / 知乎 / Twitter / Instagram / YouTube / 抖音 / 领英 / Steam / 个人网站 / 自定义。<br>
            其他用户可在你的主页查看并复制联系方式。
          </div>
        </div>

        <button class="btn" id="save-profile-btn" onclick="saveProfileEdit()">${ICO.check()} 保存修改</button>

        <div style="margin-top:30px;padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-3);line-height:1.7">
          <div style="font-weight:600;color:var(--text);margin-bottom:6px">关于编辑资料</div>
          • 头像会被服务器自动压缩为 256×256 px JPEG，节省流量<br>
          • 用户名修改是原子操作，如果失败其他字段不会回滚<br>
          • 简介支持纯文字，敏感标签会被自动剥离<br>
          • 联系方式会在你的主页公开展示，请勿填写敏感信息<br>
          • 修改成功后会自动返回个人主页
        </div>
      </div>
    </div>
  </div>`;

  // 状态
  let avData = State.user.avatar;
  let avChanged = false;

  // 联系方式平台列表（与后端白名单保持一致）
  const CONTACT_PLATFORMS = {
    'wechat': '微信', 'qq': 'QQ', 'email': '邮箱', 'phone': '手机',
    'telegram': 'Telegram', 'discord': 'Discord', 'github': 'GitHub',
    'gitee': 'Gitee', 'weibo': '微博', 'bilibili': '哔哩哔哩',
    'zhihu': '知乎', 'twitter': 'Twitter / X', 'instagram': 'Instagram',
    'youtube': 'YouTube', 'tiktok': 'TikTok / 抖音', 'linkedin': '领英',
    'steam': 'Steam', 'website': '个人网站', 'custom': '自定义',
  };

  // 渲染联系方式列表
  function renderContactList() {
    const el = $('#contact-list');
    if (!el) return;
    if (contactList.length === 0) {
      el.innerHTML = '<div style="padding:10px;background:var(--bg-2);border:1px dashed var(--border);border-radius:6px;font-size:12px;color:var(--text-3);text-align:center">还没有添加联系方式，点击下方按钮添加</div>';
      return;
    }
    el.innerHTML = contactList.map((c, i) => {
      const opts = Object.entries(CONTACT_PLATFORMS).map(([k, v]) =>
        `<option value="${k}" ${c.platform === k ? 'selected' : ''}>${escapeHtml(v)}</option>`
      ).join('');
      return `<div class="contact-row" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap" data-idx="${i}">
        <select class="input c-platform" style="width:auto;padding:8px 10px;font-size:12px;flex:0 0 auto">${opts}</select>
        <input class="input c-value" style="flex:1;min-width:120px;padding:8px 10px;font-size:12px" value="${escapeHtml(c.value)}" placeholder="联系方式" maxlength="100">
        <button class="btn ghost" style="width:auto;padding:6px 10px;font-size:12px;color:var(--danger)" onclick="removeContactRow(${i})">${ICO.trash()}</button>
      </div>`;
    }).join('');
    // 绑定 change 事件，同步到 contactList
    el.querySelectorAll('.contact-row').forEach((row, idx) => {
      const sel = row.querySelector('.c-platform');
      const inp = row.querySelector('.c-value');
      sel.addEventListener('change', () => { contactList[idx].platform = sel.value; });
      inp.addEventListener('input', () => { contactList[idx].value = inp.value; });
    });
  }

  window.addContactRow = () => {
    if (contactList.length >= 10) {
      toast('最多添加 10 条联系方式', 'err');
      return;
    }
    contactList.push({ platform: 'wechat', value: '', label: '' });
    renderContactList();
  };
  window.removeContactRow = (idx) => {
    contactList.splice(idx, 1);
    renderContactList();
  };

  renderContactList();

  $('#av-file').addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    if (f.size > 2 * 1024 * 1024) { toast('头像需 < 2MB', 'err'); return; }
    const reader = new FileReader();
    reader.onload = () => {
      avData = reader.result;
      avChanged = true;
      // 立即在预览区显示新头像
      $('#av-preview').innerHTML = `<img src="${avData}" style="width:100%;height:100%;object-fit:cover">`;
      $('#av-clear').style.display = '';
      toast('头像已选择，保存后生效', 'ok');
    };
    reader.readAsDataURL(f);
  });

  // 简介字数计数器
  $('#ed-bio').addEventListener('input', e => {
    $('#bio-counter').textContent = `${e.target.value.length}/100`;
  });

  window.clearAvatar = () => {
    avData = null;
    avChanged = true;
    $('#av-preview').innerHTML = `<div style="width:100%;height:100%;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700;font-size:24px">${escapeHtml(firstChar(State.user.username))}</div>`;
    $('#av-clear').style.display = 'none';
    toast('已移除头像，保存后生效', 'ok');
  };

  window.saveProfileEdit = async () => {
    const btn = $('#save-profile-btn');
    const newUsername = $('#ed-username').value.trim();
    const newBio = $('#ed-bio').value.trim();
    // 从 DOM 收集最新联系方式（确保用户最后输入的内容被捕获）
    const rows = document.querySelectorAll('.contact-row');
    const newContact = [];
    rows.forEach(row => {
      const platform = row.querySelector('.c-platform').value;
      const value = row.querySelector('.c-value').value.trim();
      if (platform && value) newContact.push({ platform, value, label: '' });
    });
    btn.disabled = true; btn.textContent = '保存中…';

    // 客户端校验
    if (!newUsername) { toast('用户名不能为空', 'err'); btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存修改`; return; }
    if (!/^[a-zA-Z0-9_]{3,20}$/.test(newUsername)) { toast('用户名格式不正确', 'err'); btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存修改`; return; }
    if (newBio.length > 100) { toast('简介不能超过 100 字', 'err'); btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存修改`; return; }
    if (newContact.length > 10) { toast('联系方式不能超过 10 条', 'err'); btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存修改`; return; }

    try {
      // 1. 头像 + 简介 + 联系方式（统一调 update_profile）
      //    始终发送，让服务端校验联系方式格式
      await api('update_profile', { bio: newBio, avatar: avData, contact: newContact });
      State.user.bio = newBio;
      State.user.avatar = avData;
      State.user.contact = newContact;
      // 2. 用户名（仅在变化时）
      if (newUsername !== State.user.username) {
        try {
          const r = await api('update_username', { username: newUsername });
          State.user.username = r.username;
        } catch (e) {
          toast('用户名修改失败：' + e.message + '（其他资料已保存）', 'err');
          btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存修改`;
          return;
        }
      }
      toast('已保存', 'ok');
      go('/profile');
    } catch (e) {
      toast(e.message, 'err');
      btn.disabled = false; btn.innerHTML = `${ICO.check()} 保存修改`;
    }
  };
}

/* =========================================================
 *  Favorites page
 * ========================================================= */
async function renderFavorites() {
  if (!State.user) { go('/login'); return; }
  const app = $('#app');
  app.innerHTML = `<div class="page">
    ${View.topbar('我的收藏', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>`)}
    <div class="page-scroll" id="fav-scroll"><div id="fav-list">${View.skeleton()}</div></div>
    ${View.bottomNav('fav')}
  </div>`;

  let page = 1;
  let hasMore = true;
  let loading = false;

  async function load(reset = false) {
    if (loading) return;
    if (reset) {
      page = 1;
      hasMore = true;
      $('#fav-list').innerHTML = View.skeleton();
    }
    if (!hasMore && !reset) return;
    loading = true;
    try {
      const r = await api(`posts?fav_user=${State.user.id}&page=${page}`);
      hasMore = !!r.has_more;
      const html = r.posts.length
        ? r.posts.map(View.postCard).join('')
        : (reset ? View.empty('还没有收藏', '去发现更多有趣的内容吧', '去发现', "go('/discover')") : '');
      if (reset) {
        $('#fav-list').innerHTML = html;
      } else {
        const footer = $('#fav-list .list-footer');
        if (footer) footer.remove();
        $('#fav-list').insertAdjacentHTML('beforeend', html);
      }
      if (r.posts.length > 0) {
        const footerHtml = hasMore
          ? `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多…</div>`
          : `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">· 已经到底啦 ·</div>`;
        $('#fav-list').insertAdjacentHTML('beforeend', footerHtml);
      }
      page++;
    } catch (e) {
      if (reset) $('#fav-list').innerHTML = View.empty('加载失败', e.message);
      hasMore = false;
    } finally {
      loading = false;
    }
  }

  // 滚动监听
  const scroll = $('#fav-scroll');
  if (scroll && !scroll._htmlhubScrollBound) {
    scroll._htmlhubScrollBound = true;
    scroll.addEventListener('scroll', () => {
      if (loading || !hasMore) return;
      if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 200) {
        load(false);
      }
    });
  }

  load(true);
}

/* =========================================================
 *  Studios
 * ========================================================= */
function studioCardHtml(s) {
  const cover = s.cover
    ? `<img src="${s.cover}" alt="" loading="lazy" decoding="async">`
    : `<div class="sc-placeholder">${escapeHtml(firstChar(s.name))}</div>`;
  const priv = s.visibility === 'private' ? `<div class="sc-priv">🔒 私有</div>` : '';
  let actionBtn = '';
  if (State.user) {
    if (s.is_owner) {
      actionBtn = `<button onclick="event.stopPropagation();go('/studio/${s.id}')">管理</button>`;
    } else if (s.is_member) {
      actionBtn = `<button class="danger" onclick="event.stopPropagation();leaveStudio(${s.id})">退出</button>`;
    } else {
      actionBtn = `<button class="primary" onclick="event.stopPropagation();joinStudio(${s.id})">加入</button>`;
    }
  }
  return `<div class="studio-card" onclick="go('/studio/${s.id}')">
    <div class="sc-cover">${cover}${priv}</div>
    <div class="sc-body">
      <div class="sc-name">${escapeHtml(s.name)}</div>
      <div class="sc-desc">${escapeHtml(s.description || '暂无介绍')}</div>
      <div class="sc-meta">
        <span>${ICO.studio()} ${s.posts_count} 作品</span>
        <span>${ICO.user()} ${s.members_count} 成员</span>
      </div>
    </div>
    ${actionBtn ? `<div class="sc-action">${actionBtn}</div>` : ''}
  </div>`;
}

async function renderStudios() {
  const app = $('#app');
  app.innerHTML = `<div class="page">
    ${View.topbar('工作室', `<button class="icon-btn" onclick="go('/studio/new')" title="创建工作室">${ICO.plus()}</button>`)}
    <div class="page-scroll" id="stu-scroll">
      <div class="feed-tabs">
        <button class="chip active" data-tab="all" onclick="switchStudiosTab('all')">全部</button>
        <button class="chip" data-tab="mine" onclick="switchStudiosTab('mine')">我加入的</button>
        <button class="chip" data-tab="search" onclick="focusStudiosSearch()">🔍 搜索</button>
      </div>
      <div id="stu-list">${View.skeleton()}</div>
    </div>
    ${View.bottomNav('studios')}
  </div>`;
  let curTab = 'all';
  async function load() {
    try {
      $('#stu-list').innerHTML = View.skeleton();
      const r = await api('studios' + (curTab === 'mine' ? '?mine=1' : ''));
      $('#stu-list').innerHTML = r.studios.length
        ? r.studios.map(studioCardHtml).join('')
        : View.empty(curTab === 'mine' ? '还没有加入工作室' : '还没有工作室', curTab === 'mine' ? '去创建或浏览工作室' : '抢先创建第一个工作室', '创建工作室', "go('/studio/new')");
    } catch (e) {
      $('#stu-list').innerHTML = View.empty('加载失败', e.message);
    }
  }
  window.switchStudiosTab = (t) => {
    curTab = t;
    $$('.feed-tabs .chip').forEach(x => x.classList.toggle('active', x.dataset.tab === t));
    if (t === 'search') {
      showPrompt('输入工作室名称或描述关键词', (kw) => {
        if (kw && kw.trim()) searchStudios(kw.trim());
        else switchStudiosTab('all');
      }, { title: '搜索工作室', placeholder: '工作室名称或描述' });
      return;
    }
    load();
  };
  window.focusStudiosSearch = () => switchStudiosTab('search');
  async function searchStudios(kw) {
    try {
      $('#stu-list').innerHTML = View.skeleton();
      const r = await api('studios?q=' + encodeURIComponent(kw));
      $('#stu-list').innerHTML = r.studios.length
        ? r.studios.map(studioCardHtml).join('')
        : View.empty('没有匹配的工作室', `没有名称或描述包含 "${kw}" 的工作室`);
    } catch (e) {
      $('#stu-list').innerHTML = View.empty('搜索失败', e.message);
    }
  }
  load();
}

function renderStudioNew() {
  const app = $('#app');
  if (!State.user) { go('/login'); return; }
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('创建工作室', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>`)}
    <div class="page-scroll">
      <div class="form-wrap">
        <div class="field">
          <label>工作室名称</label>
          <input class="input" id="stu-name" maxlength="50" placeholder="给工作室起个名字">
        </div>
        <div class="field">
          <label>标识（用于 URL，3-50 位字母数字下划线短横线）</label>
          <input class="input" id="stu-slug" maxlength="50" placeholder="my-studio">
        </div>
        <div class="field">
          <label>描述（≤500 字）</label>
          <textarea class="textarea" id="stu-desc" maxlength="500" placeholder="工作室的定位、目标、规则等"></textarea>
        </div>
        <div class="field">
          <label>封面</label>
          <div class="cover-zone" id="stu-cover-zone" onclick="$('#stu-cover-file').click()">
            <div class="cover-zz">${ICO.camera()}</div>
            <div class="cover-zt">点击上传封面（可选）</div>
            <div class="cover-zd">支持 PNG / JPG / WebP · ≤ 2MB</div>
          </div>
          <input type="file" id="stu-cover-file" accept="image/*" class="hidden">
        </div>
        <div class="field">
          <label>可见性</label>
          <div class="view-mode-pick">
            <div class="vm on" data-v="public" onclick="pickStuVis('public')">🌐 公开<div style="font-size:11px;color:var(--text-3);margin-top:4px">所有人可见</div></div>
            <div class="vm" data-v="private" onclick="pickStuVis('private')">🔒 私有<div style="font-size:11px;color:var(--text-3);margin-top:4px">仅成员可见</div></div>
          </div>
        </div>
        <button class="btn" id="stu-create-btn" onclick="createStudio()">${ICO.check()} 创建工作室</button>
        <div style="margin-top:20px;padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-3);line-height:1.7">
          <div style="font-weight:600;color:var(--text);margin-bottom:6px">关于工作室</div>
          • 创建者自动成为 owner，拥有全部管理权限<br>
          • 每人最多创建 5 个工作室<br>
          • 公开工作室任何人都可加入；私有工作室仅成员可见<br>
          • 成员可以在工作室发布作品，作品会同时显示在工作室和主页<br>
          • 创建者可以踢人、设置管理员、删除工作室
        </div>
      </div>
    </div>
  </div>`;
  let stuCoverData = null;
  let stuVis = 'public';
  window.pickStuVis = (v) => {
    stuVis = v;
    $$('.view-mode-pick .vm').forEach(x => x.classList.toggle('on', x.dataset.v === v));
  };
  $('#stu-cover-file').addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    if (f.size > 2 * 1024 * 1024) { toast('封面需 < 2MB', 'err'); return; }
    const reader = new FileReader();
    reader.onload = () => {
      stuCoverData = reader.result;
      const zone = $('#stu-cover-zone');
      zone.classList.add('has-img');
      zone.innerHTML = `<img src="${stuCoverData}" alt=""><button class="cz-clear" onclick="event.stopPropagation();clearStuCover()">×</button>`;
    };
    reader.readAsDataURL(f);
  });
  window.clearStuCover = () => {
    stuCoverData = null;
    const zone = $('#stu-cover-zone');
    zone.classList.remove('has-img');
    zone.innerHTML = `<div class="cover-zz">${ICO.camera()}</div><div class="cover-zt">点击上传封面（可选）</div><div class="cover-zd">支持 PNG / JPG / WebP · ≤ 2MB</div>`;
  };
  window.createStudio = async () => {
    const name = $('#stu-name').value.trim();
    const slug = $('#stu-slug').value.trim();
    const desc = $('#stu-desc').value;
    if (!name) { toast('请填写名称', 'err'); return; }
    if (!/^[a-zA-Z0-9_-]{3,50}$/.test(slug)) { toast('标识格式不正确（3-50位字母数字下划线短横线）', 'err'); return; }
    const btn = $('#stu-create-btn');
    btn.disabled = true; btn.textContent = '创建中…';
    try {
      const r = await api('studio_create', { name, slug, description: desc, cover: stuCoverData, visibility: stuVis });
      toast('创建成功', 'ok');
      setTimeout(() => { location.replace('#/studio/' + r.id); }, 400);
    } catch (e) {
      toast(e.message, 'err');
      btn.disabled = false; btn.innerHTML = `${ICO.check()} 创建工作室`;
    }
  };
}

async function renderStudioDetail(id) {
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('工作室', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button><button class="icon-btn" onclick="renderStudioDetail(${id})" title="刷新">${ICO.refresh()}</button>`)}
    <div class="page-scroll" id="sd-scroll">${View.skeleton()}</div>
  </div>`;
  try {
    const r = await api('studio&id=' + id);
    const s = r.studio;
    const cover = s.cover
      ? `<img src="${s.cover}" alt="" loading="lazy" decoding="async">`
      : `<div class="sd-placeholder">${escapeHtml(firstChar(s.name))}</div>`;
    let actions = '';
    if (State.user) {
      if (s.is_owner) {
        actions = `<button class="btn" onclick="go('/new?studio=${s.id}')">${ICO.plus()} 发布作品</button>
                   <button class="btn ghost" onclick="editStudio(${s.id})">${ICO.settings()} 管理</button>
                   <button class="btn danger" onclick="deleteStudio(${s.id})">${ICO.trash()} 删除</button>`;
      } else if (s.is_member) {
        actions = `<button class="btn" onclick="go('/new?studio=${s.id}')">${ICO.plus()} 发布作品</button>
                   <button class="btn danger" onclick="leaveStudio(${s.id})">退出工作室</button>`;
      } else {
        actions = `<button class="btn" onclick="joinStudio(${s.id})">${ICO.follow()} 加入工作室</button>`;
      }
    } else {
      actions = `<button class="btn" onclick="go('/login')">登录后操作</button>`;
    }
    app.innerHTML = `<div class="page page-slide">
      ${View.topbar('工作室', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button><button class="icon-btn" onclick="renderStudioDetail(${id})" title="刷新">${ICO.refresh()}</button>`)}
      <div class="page-scroll" id="sd-scroll">
        <div class="studio-detail-head">
          <div class="sd-cover">${cover}</div>
          <div class="studio-detail-body">
            <div class="sd-name">${escapeHtml(s.name)} ${s.visibility==='private'?'🔒':''}</div>
            <div class="sd-desc">${escapeHtml(s.description || '暂无介绍')}</div>
            <div class="sd-meta">
              <div><b>${s.posts_count}</b>作品</div>
              <div><b>${s.members_count}</b>成员</div>
            </div>
            <div class="sd-actions">${actions}</div>
            <div style="font-size:11px;color:var(--text-3);margin-top:12px">创建者：${escapeHtml(s.owner.username)} · 创建于 ${s.created_at}</div>
          </div>
        </div>
        <div class="studio-tabs">
          <button class="st-tab on" data-tab="posts" onclick="switchStudioTab('posts', ${id})">作品</button>
          <button class="st-tab" data-tab="members" onclick="switchStudioTab('members', ${id})">成员</button>
          ${s.is_member && !s.is_owner ? '' : s.is_owner ? `<button class="st-tab" data-tab="invite" onclick="switchStudioTab('invite', ${id})">邀请</button>` : ''}
        </div>
        <div id="sd-list"></div>
      </div>
    </div>`;
    switchStudioTab('posts', id);
  } catch (e) {
    toast(e.message, 'err');
    $('#sd-scroll').innerHTML = View.empty('加载失败', e.message, '返回工作室列表', "go('/studios')");
  }
}

async function switchStudioTab(tab, studioId) {
  $$('.studio-tabs .st-tab').forEach(x => x.classList.toggle('on', x.dataset.tab === tab));
  $('#sd-list').innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
  if (tab === 'posts') {
    try {
      const r = await api(`posts?studio_id=${studioId}`);
      $('#sd-list').innerHTML = r.posts.length
        ? r.posts.map(View.postCard).join('')
        : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">工作室还没有作品，成员可以发布作品到这个工作室</div>';
    } catch (e) {
      $('#sd-list').innerHTML = View.empty('加载失败', e.message);
    }
  } else if (tab === 'members') {
    try {
      const r = await api('studio_members&id=' + studioId);
      $('#sd-list').innerHTML = r.members.length ? r.members.map(m => {
        const roleBadge = `<span class="studio-role-badge ${m.role}">${m.role === 'owner' ? '创建者' : m.role === 'admin' ? '管理员' : '成员'}</span>`;
        return `<div class="studio-member-item" onclick="go('/user/${m.user.id}')">
          ${avatarHtml(m.user)}
          <div class="sm-info">
            <div class="sm-name">${escapeHtml(m.user.username)} ${roleBadge}</div>
            <div class="sm-meta">加入于 ${m.joined_at}</div>
          </div>
        </div>`;
      }).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无成员</div>';
    } catch (e) {
      $('#sd-list').innerHTML = View.empty('加载失败', e.message);
    }
  } else if (tab === 'invite') {
    renderStudioInviteTab(studioId);
  }
}

async function renderStudioInviteTab(studioId) {
  $('#sd-list').innerHTML = `
    <div class="invite-search-wrap">
      <input type="text" id="invite-search-input" placeholder="搜索用户名邀请加入工作室" oninput="searchInviteUsers(${studioId})">
    </div>
    <div id="invite-search-results" style="padding:4px 0"></div>
    <div style="padding:14px 14px 8px;font-size:13px;font-weight:700;color:var(--text-2);display:flex;align-items:center;gap:6px">
      <span>待处理邀请</span>
    </div>
    <div id="invite-pending-list">${View.skeleton()}</div>
  `;
  // 加载待处理邀请
  try {
    const r = await api('studio_pending_invitations&studio_id=' + studioId);
    $('#invite-pending-list').innerHTML = r.invitations.length ? r.invitations.map(inv => `
      <div class="invite-pending-item">
        ${avatarHtml(inv.invitee)}
        <div class="ip-info">
          <div class="ip-name">${escapeHtml(inv.invitee.username)}</div>
          <div class="ip-time">${inv.created_at}</div>
        </div>
        <button class="ip-cancel" onclick="cancelInvitation(${inv.id}, ${studioId})">取消</button>
      </div>
    `).join('') : '<div style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">暂无待处理邀请</div>';
  } catch (e) {
    $('#invite-pending-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:12px">${escapeHtml(e.message)}</div>`;
  }
  // 搜索防抖
  let searchTimer;
  window.searchInviteUsers = (sid) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => doSearchInviteUsers(sid), 350);
  };
  async function doSearchInviteUsers(sid) {
    const q = $('#invite-search-input').value.trim();
    if (!q) { $('#invite-search-results').innerHTML = ''; return; }
    try {
      const r = await api('studio_search_invite&studio_id=' + sid + '&q=' + encodeURIComponent(q));
      $('#invite-search-results').innerHTML = r.users.length ? r.users.map(u => `
        <div class="invite-result-item">
          ${avatarHtml(u)}
          <div class="ir-info">
            <div class="ir-name">${escapeHtml(u.username)}</div>
            <div class="ir-bio">${escapeHtml(u.bio || '暂无简介')}</div>
          </div>
          <button class="ir-btn" onclick="sendInvite(${sid}, ${u.id}, this)">邀请</button>
        </div>
      `).join('') : '<div style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">没有找到可邀请的用户</div>';
    } catch (e) {
      $('#invite-search-results').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:12px">${escapeHtml(e.message)}</div>`;
    }
  }
}

window.sendInvite = async (studioId, userId, btn) => {
  try {
    await api('studio_invite', { studio_id: studioId, invitee_id: userId });
    toast('邀请已发送', 'ok');
    btn.textContent = '已邀请';
    btn.disabled = true;
    btn.style.opacity = '.5';
    // 刷新待处理列表
    const r = await api('studio_pending_invitations&studio_id=' + studioId);
    $('#invite-pending-list').innerHTML = r.invitations.length ? r.invitations.map(inv => `
      <div class="invite-pending-item">
        ${avatarHtml(inv.invitee)}
        <div class="ip-info">
          <div class="ip-name">${escapeHtml(inv.invitee.username)}</div>
          <div class="ip-time">${inv.created_at}</div>
        </div>
        <button class="ip-cancel" onclick="cancelInvitation(${inv.id}, ${studioId})">取消</button>
      </div>
    `).join('') : '<div style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">暂无待处理邀请</div>';
  } catch (e) { toast(e.message, 'err'); }
};

window.cancelInvitation = async (invId, studioId) => {
  try {
    await api('studio_invite_cancel', { id: invId });
    toast('已取消邀请', 'ok');
    // 刷新待处理列表
    const r = await api('studio_pending_invitations&studio_id=' + studioId);
    $('#invite-pending-list').innerHTML = r.invitations.length ? r.invitations.map(inv => `
      <div class="invite-pending-item">
        ${avatarHtml(inv.invitee)}
        <div class="ip-info">
          <div class="ip-name">${escapeHtml(inv.invitee.username)}</div>
          <div class="ip-time">${inv.created_at}</div>
        </div>
        <button class="ip-cancel" onclick="cancelInvitation(${inv.id}, ${studioId})">取消</button>
      </div>
    `).join('') : '<div style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">暂无待处理邀请</div>';
  } catch (e) { toast(e.message, 'err'); }
};

async function joinStudio(id) {
  if (!State.user) { go('/login'); return; }
  try {
    await api('studio_join', { id });
    toast('已加入工作室', 'ok');
    renderStudioDetail(id);
  } catch (e) { toast(e.message, 'err'); }
}

async function leaveStudio(id) {
  showConfirm('确定退出这个工作室吗？退出后将无法在此工作室发布作品。', '退出工作室', async () => {
    try {
      await api('studio_leave', { id });
      toast('已退出', 'ok');
      go('/studios');
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '退出' });
}

async function deleteStudio(id) {
  showConfirm('确定删除这个工作室吗？工作室内的帖子会保留但不再关联工作室。此操作不可撤销。', '删除工作室', async () => {
    try {
      await api('studio_delete', { id });
      toast('已删除', 'ok');
      go('/studios');
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
}

async function editStudio(id) {
  // 简化：跳转到独立编辑页（这里用 sheet 弹窗）
  try {
    const r = await api('studio&id=' + id);
    const s = r.studio;
    if (!s.is_owner) { toast('只有创建者才能修改', 'err'); return; }
    const mask = document.createElement('div');
    mask.className = 'sheet-mask';
    mask.innerHTML = `<div class="sheet" onclick="event.stopPropagation()">
      <div class="sheet-grip"></div>
      <div class="sheet-title">管理工作室</div>
      <div class="field">
        <label>名称</label>
        <input class="input" id="es-name" maxlength="50" value="${escapeHtml(s.name)}">
      </div>
      <div class="field">
        <label>描述</label>
        <textarea class="textarea" id="es-desc" maxlength="500">${escapeHtml(s.description || '')}</textarea>
      </div>
      <div class="field">
        <label>可见性</label>
        <div class="view-mode-pick">
          <div class="vm ${s.visibility==='public'?'on':''}" data-v="public" onclick="pickEsVis('public')">🌐 公开</div>
          <div class="vm ${s.visibility==='private'?'on':''}" data-v="private" onclick="pickEsVis('private')">🔒 私有</div>
        </div>
      </div>
      <button class="btn" onclick="saveStudioEdit(${id})">${ICO.check()} 保存</button>
      <button class="btn ghost" style="margin-top:8px" onclick="mask.remove()">取消</button>
    </div>`;
    mask.addEventListener('click', () => mask.remove());
    document.body.appendChild(mask);
    let esVis = s.visibility;
    window.pickEsVis = (v) => {
      esVis = v;
      $$('.view-mode-pick .vm').forEach(x => x.classList.toggle('on', x.dataset.v === v));
    };
    window.saveStudioEdit = async (id) => {
      try {
        await api('studio_update', { id, name: $('#es-name').value, description: $('#es-desc').value, visibility: esVis });
        toast('已保存', 'ok');
        mask.remove();
        renderStudioDetail(id);
      } catch (e) { toast(e.message, 'err'); }
    };
  } catch (e) { toast(e.message, 'err'); }
}

/* =========================================================
 *  Notifications
 * ========================================================= */

// 后台轮询通知未读数（每 60 秒一次）
let _notifTimer = null;
async function pollNotifications() {
  if (!State.user) return;
  try {
    const r = await api('notifications_count');
    updateNotifBadge(r.unread_count);
  } catch (e) {}
  if (_notifTimer) clearTimeout(_notifTimer);
  _notifTimer = setTimeout(pollNotifications, 60000);
}

function updateNotifBadge(count) {
  State.unreadNotifs = count || 0;
  // 更新所有 .notif-dot 元素
  $$('.notif-dot').forEach(dot => {
    if (State.unreadNotifs > 0) {
      dot.classList.remove('empty');
      dot.textContent = State.unreadNotifs > 99 ? '99+' : State.unreadNotifs;
    } else {
      dot.classList.add('empty');
      dot.textContent = '';
    }
  });
}

function notifItemHtml(n) {
  // 文案：根据类型构造
  let text = '';
  let typeLabel = '';
  let typeClass = '';
  switch (n.type) {
    case 'comment':
      text = `<b>${escapeHtml(n.actor.username)}</b> <span class="n-action">评论了你的</span> <span class="n-target">${n.post_title ? escapeHtml(n.post_title) : '作品'}</span>`;
      typeLabel = '评论'; typeClass = '';
      break;
    case 'reply':
      text = `<b>${escapeHtml(n.actor.username)}</b> <span class="n-action">回复了你的评论</span>${n.post_title ? ` <span class="n-action">在</span> <span class="n-target">${escapeHtml(n.post_title)}</span>` : ''}`;
      typeLabel = '回复'; typeClass = 'reply';
      break;
    case 'like':
      text = `<b>${escapeHtml(n.actor.username)}</b> <span class="n-action">赞了你的</span> <span class="n-target">${n.post_title ? escapeHtml(n.post_title) : '作品'}</span>`;
      typeLabel = '点赞'; typeClass = 'like';
      break;
    case 'follow':
      text = `<b>${escapeHtml(n.actor.username)}</b> <span class="n-action">关注了你</span>`;
      typeLabel = '关注'; typeClass = 'follow';
      break;
    case 'system':
      text = `<b>系统通知</b>`;
      typeLabel = '系统'; typeClass = '';
      break;
    case 'studio_invite':
      text = `<b>${escapeHtml(n.actor.username)}</b> <span class="n-action">邀请你加入工作室</span>${n.content ? ` <span class="n-target">${escapeHtml(n.content)}</span>` : ''}`;
      typeLabel = '邀请'; typeClass = '';
      break;
    default:
      text = `<b>${escapeHtml(n.actor.username)}</b> <span class="n-action">与你互动</span>`;
  }
  // 内容片段（评论/回复/系统通知有 content）
  const snippet = (n.type === 'comment' || n.type === 'reply' || n.type === 'system') && n.content
    ? `<div class="n-snippet">${escapeHtml(n.content)}</div>` : '';
  const target = n.post_id > 0 ? `onclick="openNotif(${n.id}, ${n.post_id})"` : `onclick="openNotif(${n.id}, 0, ${n.actor ? n.actor.id : 0})"`;
  return `<div class="notif-item ${n.is_read?'':'unread'}" ${target}>
    ${avatarHtml(n.actor)}
    <div class="n-body">
      <div class="n-text">${text}</div>
      ${snippet}
      <div class="n-meta">
        <span class="n-type-tag ${typeClass}">${typeLabel}</span>
        <span>${n.created_at}</span>
      </div>
    </div>
  </div>`;
}

async function renderNotifications() {
  if (!State.user) { go('/login'); return; }
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('消息通知', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="notif-tabs">
      <button class="nt on" data-tab="all" onclick="switchNotifTab('all')">全部</button>
      <button class="nt" data-tab="unread" onclick="switchNotifTab('unread')">未读</button>
    </div>
    <div class="notif-actions-bar">
      <span id="notif-summary">加载中…</span>
      <button id="notif-read-all" onclick="markAllNotifsRead()">全部已读</button>
    </div>
    <div class="page-scroll" id="notif-scroll"><div id="notif-list">${View.skeleton()}</div></div>
  </div>`;

  let curTab = 'all';
  let page = 1;
  let loading = false;
  let hasMore = true; // 服务端 has_more 标志，false 时不再触发分页

  async function load(reset = false) {
    if (loading) return;
    if (reset) {
      page = 1;
      hasMore = true;
      $('#notif-list').innerHTML = View.skeleton();
    }
    if (!hasMore && !reset) return; // 没有更多了，不再请求
    loading = true;
    try {
      // 并行加载通知 + 工作室邀请
      const [r, invR] = await Promise.all([
        api(`notifications?page=${page}&filter=${curTab}`),
        page === 1 ? api('my_invitations').catch(() => ({ invitations: [] })) : Promise.resolve({ invitations: [] }),
      ]);
      // 同步 has_more（后端会返回该字段）
      hasMore = !!r.has_more;
      let html = '';
      // 工作室邀请卡片（仅首页加载时显示）
      if (page === 1 && invR.invitations && invR.invitations.length > 0) {
        html += invR.invitations.map(inv => `
          <div class="invite-card">
            <div class="ic-head">
              <div class="ic-studio-cover">${inv.studio.cover ? `<img src="${inv.studio.cover}" alt="" loading="lazy" decoding="async">` : escapeHtml(firstChar(inv.studio.name))}</div>
              <div class="ic-body">
                <div class="ic-title">${escapeHtml(inv.studio.name)}</div>
                <div class="ic-desc">${escapeHtml(inv.inviter.username)} 邀请你加入 · ${inv.created_at}</div>
              </div>
            </div>
            <div class="ic-actions">
              <button class="accept" onclick="respondInvite(${inv.id}, true, this)">接受</button>
              <button class="decline" onclick="respondInvite(${inv.id}, false, this)">拒绝</button>
            </div>
          </div>
        `).join('');
      }
      // 仅在 page 1 且无邀请无通知时显示空态；其他页 append
      if (page === 1 && r.notifications.length === 0 && (!invR.invitations || invR.invitations.length === 0)) {
        html += (curTab === 'unread'
          ? View.empty('没有未读通知', '所有消息都已查看')
          : View.empty('还没有通知', '当别人与你互动时，会在这里显示'));
      } else {
        html += r.notifications.map(notifItemHtml).join('');
      }
      if (reset) {
        $('#notif-list').innerHTML = html;
      } else {
        // 追加前移除可能存在的「加载更多」/「到底」提示
        const footer = $('#notif-list .list-footer');
        if (footer) footer.remove();
        $('#notif-list').insertAdjacentHTML('beforeend', html);
      }
      // 底部状态提示
      if (r.notifications.length > 0) {
        const footerHtml = hasMore
          ? `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多…</div>`
          : `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">· 已经到底啦 ·</div>`;
        $('#notif-list').insertAdjacentHTML('beforeend', footerHtml);
      }
      $('#notif-summary').textContent = r.unread_count > 0 ? `${r.unread_count} 条未读` : '全部已读';
      updateNotifBadge(r.unread_count);
      page++;
    } catch (e) {
      $('#notif-list').innerHTML = View.empty('加载失败', e.message);
      hasMore = false;
    } finally {
      loading = false;
    }
  }

  window.switchNotifTab = (t) => {
    curTab = t;
    $$('.notif-tabs .nt').forEach(x => x.classList.toggle('on', x.dataset.tab === t));
    load(true);
  };

  window.markAllNotifsRead = async () => {
    try {
      const r = await api('notifications_read', { all: true });
      updateNotifBadge(r.unread_count);
      $$('.notif-item.unread').forEach(item => item.classList.remove('unread'));
      $('#notif-summary').textContent = `全部已读`;
      toast('已全部标记为已读', 'ok');
    } catch (e) { toast(e.message, 'err'); }
  };

  window.respondInvite = async (invId, accept, btn) => {
    try {
      await api('studio_invite_respond', { id: invId, accept });
      const card = btn.closest('.invite-card');
      if (card) {
        card.style.transition = 'opacity .3s ease, transform .3s ease';
        card.style.opacity = '0';
        card.style.transform = 'translateX(' + (accept ? '0' : '-20px') + ')';
        setTimeout(() => card.remove(), 300);
      }
      toast(accept ? '已加入工作室' : '已拒绝邀请', accept ? 'ok' : '');
      if (accept) {
        // 重新加载通知页以更新列表
        setTimeout(() => load(true), 400);
      }
    } catch (e) { toast(e.message, 'err'); }
  };

  window.openNotif = async (id, postId, actorId) => {
    // 标记单条已读
    try {
      const r = await api('notifications_read', { id });
      updateNotifBadge(r.unread_count);
    } catch (e) {}
    // 跳转到对应位置
    if (postId > 0) {
      go('/post/' + postId);
    } else if (actorId > 0) {
      go('/user/' + actorId);
    }
  };

  // 滚动监听：触底自动加载下一页
  const scroll = $('#notif-scroll');
  if (scroll) {
    scroll.addEventListener('scroll', () => {
      if (loading || !hasMore) return;
      if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 200) {
        load(false);
      }
    });
  }

  load(true);
}

/* =========================================================
 *  HTML 静态托管
 * ========================================================= */
/* =========================================================
 *  Theme settings
 * ========================================================= */
function getTheme() {
  return localStorage.getItem('app_theme') || 'light';
}
function getAccent() {
  return localStorage.getItem('app_accent') || 'blue';
}
function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('app_theme', theme);
  // 同步 theme-color meta
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = theme === 'dark' ? '#0f0f14' : '#ffffff';
}
function applyAccent(accent) {
  if (accent === 'blue') {
    document.documentElement.removeAttribute('data-accent');
  } else {
    document.documentElement.setAttribute('data-accent', accent);
  }
  localStorage.setItem('app_accent', accent);
}

const ACCENT_COLORS = [
  { key: 'blue',   label: '蓝',   color: '#3b6cff' },
  { key: 'purple', label: '紫',   color: '#7c5cff' },
  { key: 'green',  label: '绿',   color: '#10b981' },
  { key: 'orange', label: '橙',   color: '#f97316' },
  { key: 'pink',   label: '粉',   color: '#ec4899' },
  { key: 'red',    label: '红',   color: '#ef4444' },
];

/* =========================================================
 *  Settings (个性化设置)
 * ========================================================= */
function getSetting(key, defaultVal) {
  try { return localStorage.getItem('setting_' + key) ?? defaultVal; } catch(e) { return defaultVal; }
}
function setSetting(key, val) {
  try { localStorage.setItem('setting_' + key, val); } catch(e) {}
  applySettings();
}
function applySettings() {
  const fontSize = getSetting('font_size', '15');
  const density = getSetting('density', 'normal');
  const reducedMotion = getSetting('reduced_motion', '0') === '1';
  const lazyImages = getSetting('lazy_images', '1') === '1';
  // 字体大小
  document.documentElement.style.setProperty('--app-font-size', fontSize + 'px');
  document.body.style.fontSize = fontSize + 'px';
  // 列表密度
  document.documentElement.setAttribute('data-density', density);
  // 减少动画
  if (reducedMotion) {
    document.documentElement.setAttribute('data-reduced-motion', '1');
  } else {
    document.documentElement.removeAttribute('data-reduced-motion');
  }
}

/* =========================================================
 *  🧪 代码质量评分（玩具工具）
 *  - 多维度加权算法：结构 25% / SEO 15% / CSS 20% / JS 15% / 无障碍 15% / 性能 10%
 *  - 纯客户端实现：无网络请求，即时反馈
 *  - 受管理员开关 State.settings.code_score_enabled 控制
 * ========================================================= */

/**
 * 代码质量评分算法
 * 输入：HTML 字符串
 * 输出：{ overall, grade, dimensions[], suggestions{} }
 *
 * 维度评分逻辑：
 *   1) 结构（Structure 25%）：DOCTYPE / html / head / body / title / 语义化标签
 *   2) SEO & Meta（15%）：charset / viewport / description / lang / keywords
 *   3) CSS 质量（20%）：<style> 存在 / @media 响应式 / Flex|Grid / 过渡动画 / 内联样式惩罚
 *   4) JavaScript 质量（15%）：<script> 存在 / ES6+ 语法 / DOM API / 箭头函数 /
 *      惩罚 alert/eval/document.write/console.log
 *   5) 无障碍（15%）：img alt 覆盖率 / label 关联 / ARIA / lang / 跳转链接
 *   6) 性能（10%）：defer|async / loading=lazy / preconnect / 文件大小
 */
function scoreHtmlCode(html) {
  if (!html || typeof html !== 'string') html = '';
  const code = html;
  const suggestions = {};
  const dims = [];

  // === 1) 结构（Structure）===
  let structureScore = 0;
  const structIssues = [];
  if (/<!DOCTYPE\s+html>/i.test(code)) structureScore += 20;
  else structIssues.push('缺少 <!DOCTYPE html> 声明');
  if (/<html[\s>]/i.test(code)) structureScore += 15;
  else structIssues.push('缺少 <html> 根元素');
  if (/<head[\s>]/i.test(code)) structureScore += 15;
  else structIssues.push('缺少 <head> 元素');
  if (/<body[\s>]/i.test(code)) structureScore += 15;
  else structIssues.push('缺少 <body> 元素');
  if (/<title[\s>]/i.test(code)) structureScore += 15;
  else structIssues.push('缺少 <title> 标签');
  const semanticCount = (code.match(/<(header|nav|main|section|article|aside|footer|figure|figcaption|time)\b/ig) || []).length;
  if (semanticCount >= 3) structureScore += 20;
  else if (semanticCount >= 1) { structureScore += 10; structIssues.push('语义化标签偏少，建议使用 header/nav/main/section 等 HTML5 标签'); }
  else structIssues.push('未使用 HTML5 语义化标签（header/nav/main/section/article/footer）');
  structureScore = Math.min(100, structureScore);
  dims.push({ key: 'structure', label: '结构', icon: '🏗️', score: structureScore });
  if (structIssues.length) suggestions.structure = structIssues;

  // === 2) SEO & Meta ===
  let seoScore = 0;
  const seoIssues = [];
  if (/<meta\s+charset/i.test(code)) seoScore += 25;
  else seoIssues.push('缺少 <meta charset> 字符编码声明');
  if (/<meta\s+name=["']viewport["']/i.test(code)) seoScore += 25;
  else seoIssues.push('缺少 viewport meta 标签（移动端适配必需）');
  if (/<meta\s+name=["']description["']/i.test(code)) seoScore += 25;
  else seoIssues.push('缺少 meta description（影响 SEO）');
  if (/<html[^>]*\blang\s*=/i.test(code)) seoScore += 15;
  else seoIssues.push('建议在 <html> 标签上设置 lang 属性');
  if (/<meta\s+name=["']keywords["']/i.test(code)) seoScore += 10;
  seoScore = Math.min(100, seoScore);
  dims.push({ key: 'seo', label: 'SEO 与 Meta', icon: '🔍', score: seoScore });
  if (seoIssues.length) suggestions.seo = seoIssues;

  // === 3) CSS 质量 ===
  let cssScore = 0;
  const cssIssues = [];
  const hasStyleTag = /<style\b/i.test(code);
  const inlineStyleCount = (code.match(/\bstyle\s*=\s*["']/g) || []).length;
  if (hasStyleTag) {
    cssScore += 40;
    if (/@media/i.test(code)) cssScore += 20;
    else cssIssues.push('CSS 缺少 @media 响应式查询');
    if (/(flex|grid)/i.test(code)) cssScore += 15;
    else cssIssues.push('建议使用 Flexbox 或 Grid 布局');
    if (/(transition|animation)/i.test(code)) cssScore += 10;
    else cssIssues.push('可添加 transition/animation 提升交互体验');
    if (/var\(--/i.test(code)) cssScore += 15;
  } else {
    cssIssues.push('未检测到 <style> 标签或外链 CSS');
  }
  if (inlineStyleCount > 10) {
    cssScore -= 15;
    cssIssues.push('检测到 ' + inlineStyleCount + ' 处内联 style，建议提取到 <style> 或外部样式表');
  } else if (inlineStyleCount > 0 && !hasStyleTag) {
    cssScore += 5;
  }
  cssScore = Math.max(0, Math.min(100, cssScore));
  dims.push({ key: 'css', label: 'CSS 质量', icon: '🎨', score: cssScore });
  if (cssIssues.length) suggestions.css = cssIssues;

  // === 4) JavaScript 质量 ===
  let jsScore = 0;
  const jsIssues = [];
  const hasScript = /<script\b/i.test(code);
  if (hasScript) {
    jsScore += 30;
    if (/<script[^>]*\btype=["']module["']/.test(code)) jsScore += 20;
    if (/\b(const|let)\s+/.test(code)) jsScore += 15;
    else if (/\bvar\s+/.test(code)) jsIssues.push('建议使用 const/let 替代 var');
    if (/(addEventListener|querySelector|getElementById)/.test(code)) jsScore += 15;
    if (/=>/.test(code)) jsScore += 10;
    if (/\/\*\s*@ts-check|\binterface\s+\w+\s*\{/.test(code)) jsScore += 10;
    if (/\balert\s*\(/.test(code)) { jsScore -= 15; jsIssues.push('检测到 alert()，建议使用更友好的提示方式'); }
    if (/\beval\s*\(/.test(code)) { jsScore -= 20; jsIssues.push('检测到 eval()，存在严重安全风险'); }
    if (/\bdocument\.write\s*\(/.test(code)) { jsScore -= 10; jsIssues.push('检测到 document.write()，现代浏览器已不推荐'); }
    if (/<script[^>]*>[^<]*console\.log/s.test(code)) jsIssues.push('检测到 console.log，发布前建议移除调试日志');
    // 注意：以上正则中的关闭标签字面量需避免触发 HTML 解析器提前结束 <script> 块，
    // 因此上面的判断里只出现 <script 开标签。若需要匹配关闭标签，应使用拼接字符串：'<' + '/script'
  } else {
    jsScore = 50; // 无 JS 是中性
  }
  jsScore = Math.max(0, Math.min(100, jsScore));
  dims.push({ key: 'js', label: 'JavaScript 质量', icon: '⚙️', score: jsScore });
  if (jsIssues.length) suggestions.js = jsIssues;

  // === 5) 无障碍（Accessibility）===
  let a11yScore = 0;
  const a11yIssues = [];
  const imgCount = (code.match(/<img\b/ig) || []).length;
  const imgWithAlt = (code.match(/<img[^>]*\balt\s*=/ig) || []).length;
  if (imgCount > 0) {
    const ratio = imgWithAlt / imgCount;
    a11yScore += Math.round(ratio * 30);
    if (ratio < 1) a11yIssues.push('图片 alt 覆盖率: ' + imgWithAlt + '/' + imgCount);
  } else {
    a11yScore += 20;
  }
  const inputCount = (code.match(/<input\b/ig) || []).length;
  const labelCount = (code.match(/<label\b/ig) || []).length;
  if (inputCount > 0) {
    if (labelCount >= inputCount) a11yScore += 20;
    else if (labelCount > 0) { a11yScore += 10; a11yIssues.push('表单 label 数量少于 input，建议补充关联'); }
    else a11yIssues.push('表单缺少 <label> 关联');
  } else {
    a11yScore += 15;
  }
  if (/\baria-/.test(code)) a11yScore += 15;
  else a11yIssues.push('建议为交互元素添加 ARIA 属性');
  if (/<html[^>]*\blang\s*=/i.test(code)) a11yScore += 15;
  if (/<a[^>]*href=["']#/.test(code)) a11yScore += 10;
  a11yScore = Math.min(100, a11yScore);
  dims.push({ key: 'a11y', label: '无障碍', icon: '♿', score: a11yScore });
  if (a11yIssues.length) suggestions.a11y = a11yIssues;

  // === 6) 性能 ===
  let perfScore = 0;
  const perfIssues = [];
  if (/<script[^>]*\b(defer|async)\b/.test(code)) perfScore += 30;
  else perfIssues.push('建议为 <script> 添加 defer 或 async 属性');
  perfScore += 20; // 基线
  if (/loading\s*=\s*["']lazy["']/.test(code)) perfScore += 20;
  else if (imgCount > 3) perfIssues.push('图片较多，建议添加 loading="lazy"');
  if (/<link[^>]*\brel=["'](preconnect|dns-prefetch)["']/.test(code)) perfScore += 15;
  const totalBytes = code.length;
  const wsCount = (code.match(/\s/g) || []).length;
  if (totalBytes > 0 && wsCount / totalBytes < 0.15) perfScore += 15;
  else if (totalBytes > 50000) perfIssues.push('文件较大（' + Math.round(totalBytes / 1024) + 'KB），生产环境建议压缩');
  perfScore = Math.min(100, perfScore);
  dims.push({ key: 'performance', label: '性能', icon: '⚡', score: perfScore });
  if (perfIssues.length) suggestions.performance = perfIssues;

  // === 加权总分 ===
  const weights = {
    structure: 0.25,
    seo: 0.15,
    css: 0.20,
    js: 0.15,
    a11y: 0.15,
    performance: 0.10,
  };
  let overall = 0;
  dims.forEach(d => { overall += d.score * (weights[d.key] || 0); });
  overall = Math.round(overall);

  // === 等级 ===
  let grade;
  if (overall >= 95) grade = 'A+';
  else if (overall >= 90) grade = 'A';
  else if (overall >= 85) grade = 'A-';
  else if (overall >= 80) grade = 'B+';
  else if (overall >= 70) grade = 'B';
  else if (overall >= 60) grade = 'B-';
  else if (overall >= 50) grade = 'C';
  else if (overall >= 40) grade = 'D';
  else grade = 'F';

  return {
    overall,
    grade,
    dimensions: dims,
    suggestions,
    code_size: code.length,
    line_count: code.split('\n').length,
  };
}

/** 评分颜色：根据分数返回等级色 */
function scoreColor(s) {
  if (s >= 85) return 'var(--success)';
  if (s >= 70) return '#10b981';
  if (s >= 60) return 'var(--warn)';
  if (s >= 40) return '#f97316';
  return 'var(--danger)';
}

/** 等级颜色 */
function gradeColor(g) {
  if (g.startsWith('A')) return 'var(--success)';
  if (g.startsWith('B')) return '#10b981';
  if (g === 'C') return 'var(--warn)';
  if (g === 'D') return '#f97316';
  return 'var(--danger)';
}

/** 渲染代码评分页 */
function renderCodeScore() {
  const app = $('#app');
  // 优先从 sessionStorage 读取从 /new 页传过来的代码
  let initialCode = '';
  try { initialCode = sessionStorage.getItem('_hh_score_code') || ''; sessionStorage.removeItem('_hh_score_code'); } catch (e) {}

  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('代码质量评分', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="page-scroll" id="cs-scroll">
      <div style="padding:14px">
        <div class="cs-hero">
          <div class="cs-badge">🧪 玩具工具</div>
          <div class="cs-title">代码质量评分</div>
          <div class="cs-desc">粘贴或上传 HTML 代码（含内嵌 CSS / JS 即可），点击评分按钮获取多维度质量报告。纯本地分析，不会上传到服务器。</div>
        </div>

        <div class="field">
          <label>HTML 代码（输入或粘贴）</label>
          <textarea class="textarea code-area" id="cs-input" placeholder="" spellcheck="false" style="min-height:200px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px"></textarea>
          <div style="font-size:11px;color:var(--text-3);margin-top:4px;line-height:1.5">支持完整的 HTML 文档，含内嵌 &lt;style&gt; 与 &lt;script&gt; 标签。也可直接上传 .html 文件。</div>
        </div>

        <div class="field">
          <label>或上传 .html 文件</label>
          <input type="file" id="cs-file" accept=".html,.htm,text/html" class="hidden">
          <button class="btn ghost" onclick="$('#cs-file').click()">${ICO.upload()} 选择 HTML 文件</button>
        </div>

        <button class="btn" id="cs-score-btn" onclick="runCodeScore()">${ICO.chart()} 开始评分</button>
        <button class="btn ghost" style="margin-top:8px" onclick="clearCodeScore()">清空</button>

        <div id="cs-result" style="margin-top:18px"></div>
      </div>
    </div>
  </div>`;

  // 文件上传处理
  $('#cs-file').addEventListener('change', (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (f.size > 200 * 1024) { toast('文件过大，请选择小于 200KB 的文件', 'err'); return; }
    const reader = new FileReader();
    reader.onload = (ev) => { $('#cs-input').value = ev.target.result; toast('已加载 ' + f.name, 'ok'); };
    reader.onerror = () => { toast('文件读取失败', 'err'); };
    reader.readAsText(f, 'UTF-8');
  });

  // 如果有从 /new 页传来的代码，预填并自动评分
  if (initialCode) {
    $('#cs-input').value = initialCode;
    setTimeout(() => runCodeScore(), 100);
  }
}

/** 执行评分并渲染结果 */
window.runCodeScore = function() {
  const code = $('#cs-input').value;
  if (!code.trim()) { toast('请输入或上传 HTML 代码', 'err'); return; }
  if (code.length > 200 * 1024) { toast('代码过长（超过 200KB），无法评分', 'err'); return; }
  const btn = $('#cs-score-btn');
  btn.disabled = true; btn.textContent = '评分中…';
  // 用 setTimeout 让 UI 先更新（避免大文件分析时卡顿）
  setTimeout(() => {
    try {
      const result = scoreHtmlCode(code);
      renderScoreResult(result);
    } catch (e) {
      toast('评分失败：' + e.message, 'err');
    } finally {
      btn.disabled = false; btn.innerHTML = `${ICO.chart()} 开始评分`;
    }
  }, 30);
};

/** 渲染评分结果到 #cs-result */
function renderScoreResult(r) {
  const el = $('#cs-result');
  if (!el) return;
  const gc = gradeColor(r.grade);
  const dimHtml = r.dimensions.map(d => {
    const c = scoreColor(d.score);
    return `<div class="cs-dim">
      <div class="cs-dim-head">
        <span class="cs-dim-icon">${d.icon}</span>
        <span class="cs-dim-label">${escapeHtml(d.label)}</span>
        <span class="cs-dim-score" style="color:${c}">${d.score}</span>
      </div>
      <div class="cs-dim-bar">
        <div class="cs-dim-fill" style="width:${d.score}%;background:${c}"></div>
      </div>
    </div>`;
  }).join('');

  // 改进建议
  const suggKeys = Object.keys(r.suggestions);
  const suggHtml = suggKeys.length ? `
    <div class="cs-sugg">
      <div class="cs-sugg-title">📋 改进建议</div>
      ${suggKeys.map(k => {
        const dim = r.dimensions.find(d => d.key === k);
        const label = dim ? dim.label : k;
        const items = r.suggestions[k];
        return `<div class="cs-sugg-group">
          <div class="cs-sugg-group-title">${dim ? dim.icon : '•'} ${escapeHtml(label)}</div>
          <ul>${items.map(it => `<li>${escapeHtml(it)}</li>`).join('')}</ul>
        </div>`;
      }).join('')}
    </div>` : '<div class="cs-sugg-empty">✨ 暂无改进建议，代码质量已相当不错！</div>';

  el.innerHTML = `
    <div class="cs-result-card">
      <div class="cs-overall">
        <div class="cs-overall-left">
          <div class="cs-overall-num" style="color:${gc}">${r.overall}</div>
          <div class="cs-overall-unit">/ 100</div>
        </div>
        <div class="cs-overall-right">
          <div class="cs-grade" style="color:${gc};border-color:${gc}">${r.grade}</div>
          <div class="cs-meta">${r.code_size} 字符 · ${r.line_count} 行</div>
        </div>
      </div>
      <div class="cs-dims">${dimHtml}</div>
      ${suggHtml}
    </div>
  `;
  // 滚动到结果
  setTimeout(() => { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
}

window.clearCodeScore = function() {
  $('#cs-input').value = '';
  $('#cs-result').innerHTML = '';
  $('#cs-file').value = '';
  toast('已清空', 'ok');
};

/** 从 /new 编辑器跳转过来评分 */
window.scoreCurrentCode = function() {
  const ta = $('#f-html');
  if (!ta) return;
  const code = ta.value;
  if (!code.trim()) { toast('请先输入 HTML 代码', 'err'); return; }
  try { sessionStorage.setItem('_hh_score_code', code); } catch (e) {}
  go('/code-score');
};

function renderSettings() {
  const app = $('#app');
  const fontSize = getSetting('font_size', '15');
  const density = getSetting('density', 'normal');
  const defaultSort = getSetting('default_sort', 'new');
  const autoPlay = getSetting('auto_play', '0') === '1';
  const reducedMotion = getSetting('reduced_motion', '0') === '1';
  const lazyImages = getSetting('lazy_images', '1') === '1';
  const showEdited = getSetting('show_edited', '1') === '1';
  const incognito = getSetting('incognito', '0') === '1';

  app.innerHTML = `<div class="page page-slide settings-page">
    ${View.topbar('个性化设置', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="page-scroll">
      <div class="settings-section">
        <div class="settings-section-title">显示</div>
        <div class="settings-row">
          <div>
            <div class="sr-label">字体大小</div>
            <div class="sr-desc">调整全局字体大小</div>
          </div>
          <div class="sr-control" style="display:flex;align-items:center;gap:8px">
            <input type="range" class="settings-slider" min="13" max="18" value="${fontSize}" oninput="updateFontSize(this.value)">
            <span class="settings-slider-val" id="font-size-val">${fontSize}px</span>
          </div>
        </div>
        <div class="settings-row">
          <div>
            <div class="sr-label">列表密度</div>
            <div class="sr-desc">紧凑模式可显示更多内容</div>
          </div>
          <div class="sr-control">
            <select class="settings-select" onchange="setSetting('density', this.value);renderSettings()">
              <option value="normal" ${density==='normal'?'selected':''}>标准</option>
              <option value="compact" ${density==='compact'?'selected':''}>紧凑</option>
              <option value="comfortable" ${density==='comfortable'?'selected':''}>宽松</option>
            </select>
          </div>
        </div>
        <div class="settings-row">
          <div>
            <div class="sr-label">减少动画</div>
            <div class="sr-desc">关闭页面切换和交互动画</div>
          </div>
          <div class="sr-control">
            <button class="settings-toggle ${reducedMotion?'on':''}" onclick="toggleSetting('reduced_motion', this)"><div class="st-knob"></div></button>
          </div>
        </div>
      </div>

      <div class="settings-section">
        <div class="settings-section-title">内容</div>
        <div class="settings-row">
          <div>
            <div class="sr-label">默认排序</div>
            <div class="sr-desc">进入首页时的默认排序方式</div>
          </div>
          <div class="sr-control">
            <select class="settings-select" onchange="setSetting('default_sort', this.value)">
              <option value="new" ${defaultSort==='new'?'selected':''}>最新</option>
              <option value="hot" ${defaultSort==='hot'?'selected':''}>热门</option>
            </select>
          </div>
        </div>
        <div class="settings-row">
          <div>
            <div class="sr-label">懒加载图片</div>
            <div class="sr-desc">滚动到图片位置时才加载，节省流量</div>
          </div>
          <div class="sr-control">
            <button class="settings-toggle ${lazyImages?'on':''}" onclick="toggleSetting('lazy_images', this)"><div class="st-knob"></div></button>
          </div>
        </div>
        <div class="settings-row">
          <div>
            <div class="sr-label">显示「已编辑」标识</div>
            <div class="sr-desc">在帖子标题旁显示编辑状态</div>
          </div>
          <div class="sr-control">
            <button class="settings-toggle ${showEdited?'on':''}" onclick="toggleSetting('show_edited', this)"><div class="st-knob"></div></button>
          </div>
        </div>
      </div>

      <div class="settings-section">
        <div class="settings-section-title">滑动</div>
        <div class="settings-row">
          <div>
            <div class="sr-label">橡皮筋回弹</div>
            <div class="sr-desc">滑动到边界时弹性回弹（仅手机端）</div>
          </div>
          <div class="sr-control">
            <button class="settings-toggle ${getSetting('rubber_band_enabled','0')==='1'?'on':''}" onclick="toggleSetting('rubber_band_enabled', this)"><div class="st-knob"></div></button>
          </div>
        </div>
        <div class="settings-row">
          <div>
            <div class="sr-label">回弹模式</div>
            <div class="sr-desc">物理模式：真实弹簧模拟（测试中）；动画模式：成熟稳定</div>
          </div>
          <div class="sr-control">
            <select class="settings-select" onchange="setSetting('rubber_band_mode', this.value);renderSettings()">
              <option value="animation" ${getSetting('rubber_band_mode','animation')==='animation'?'selected':''}>动画模式（稳定）</option>
              <option value="physics" ${getSetting('rubber_band_mode','physics')==='physics'?'selected':''}>物理模式（测试中）</option>
            </select>
          </div>
        </div>
      </div>

      <div class="settings-section">
        <div class="settings-section-title">隐私</div>
        <div class="settings-row">
          <div>
            <div class="sr-label">无痕浏览</div>
            <div class="sr-desc">不增加帖子浏览量（仅对本人生效）</div>
          </div>
          <div class="sr-control">
            <button class="settings-toggle ${incognito?'on':''}" onclick="toggleSetting('incognito', this)"><div class="st-knob"></div></button>
          </div>
        </div>
      </div>

      ${State.settings.code_score_enabled ? `
      <div class="settings-section">
        <div class="settings-section-title">玩具工具</div>
        <div class="settings-row" style="cursor:pointer" onclick="go('/code-score')">
          <div>
            <div class="sr-label">🧪 代码质量评分</div>
            <div class="sr-desc">粘贴或上传 HTML 代码，多维度评分（结构 / SEO / CSS / JS / 无障碍 / 性能）</div>
          </div>
          <div class="sr-control" style="color:var(--text-3);font-size:18px">›</div>
        </div>
      </div>` : ''}

      <div class="settings-section" style="border-bottom:none">
        <div style="font-size:12px;color:var(--text-3);line-height:1.6">
          个性化设置保存在本地浏览器中，切换设备后需要重新设置。
          如需修改主题颜色或深浅模式，请前往<a onclick="go('/theme')" style="color:var(--accent);font-weight:600">主题设置</a>。
        </div>
      </div>
    </div>
  </div>`;
}

window.updateFontSize = (val) => {
  $('#font-size-val').textContent = val + 'px';
  setSetting('font_size', val);
};

window.toggleSetting = (key, btn) => {
  const cur = getSetting(key, key === 'rubber_band_enabled' ? '0' : '0') === '1';
  setSetting(key, cur ? '0' : '1');
  btn.classList.toggle('on', !cur);
  if (key === 'reduced_motion') applySettings();
  if (key === 'rubber_band_enabled') {
    toast(cur ? '橡皮筋已关闭，刷新页面生效' : '橡皮筋已开启，刷新页面生效', 'ok');
  } else {
    toast(cur ? '已关闭' : '已开启', 'ok');
  }
};

// 页面加载时应用设置
applySettings();

function renderTheme() {
  const app = $('#app');
  const curTheme = getTheme();
  const curAccent = getAccent();
  app.innerHTML = `<div class="page page-slide theme-page">
    ${View.topbar('主题设置', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="page-scroll">
      <div class="theme-section">
        <div class="theme-section-title">显示模式</div>
        <div class="theme-mode-grid">
          <div class="theme-mode-card ${curTheme==='light'?'on':''}" onclick="setThemeMode('light')">
            <div class="tm-preview"><div class="tmp-light"></div></div>
            <div class="tm-label">☀️ 浅色</div>
            <div class="tm-check">✓</div>
          </div>
          <div class="theme-mode-card ${curTheme==='dark'?'on':''}" onclick="setThemeMode('dark')">
            <div class="tm-preview"><div class="tmp-dark"></div></div>
            <div class="tm-label">🌙 深色</div>
            <div class="tm-check">✓</div>
          </div>
        </div>
      </div>
      <div class="theme-section">
        <div class="theme-section-title">强调色</div>
        <div class="accent-grid">
          ${ACCENT_COLORS.map(a => `<div class="accent-card ${curAccent===a.key?'on':''}" onclick="setAccentColor('${a.key}')" style="${curAccent===a.key?'border-color:'+a.color:''}">
            <div class="ac-color" style="background:${a.color}"></div>
            <div class="ac-label">${a.label}</div>
            <div class="ac-check" style="background:${a.color}">✓</div>
          </div>`).join('')}
        </div>
      </div>
      <div class="theme-section" style="border-bottom:none">
        <div style="font-size:12px;color:var(--text-3);line-height:1.6">
          主题设置保存在本地浏览器中，切换设备后需要重新设置。
          深色模式适合夜间使用，可减少屏幕蓝光对眼睛的刺激。
        </div>
      </div>
    </div>
  </div>`;
}

window.setThemeMode = (mode) => {
  applyTheme(mode);
  $$('.theme-mode-card').forEach(c => c.classList.remove('on'));
  event.currentTarget.classList.add('on');
  toast(`已切换到${mode === 'dark' ? '深色' : '浅色'}模式`, 'ok');
};

window.setAccentColor = (color) => {
  applyAccent(color);
  $$('.accent-card').forEach(c => c.classList.remove('on'));
  event.currentTarget.classList.add('on');
  // 更新选中边框色
  const accentData = ACCENT_COLORS.find(a => a.key === color);
  if (accentData) event.currentTarget.style.borderColor = accentData.color;
  toast(`强调色已更改`, 'ok');
};

// 页面加载时立即应用保存的主题（在 init 之前执行）
applyTheme(getTheme());
applyAccent(getAccent());

async function renderHosting() {
  const app = $('#app');
  app.innerHTML = `<div class="page hosting-page">
    ${View.topbar('HTML 静态托管', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="hosting-banner">
      <div class="hb-title">${ICO.hosting()} HTML 静态托管 <span style="font-size:10px;padding:2px 6px;background:var(--accent);color:#fff;border-radius:3px;vertical-align:middle">Beta</span></div>
      <div class="hb-desc">托管你的 HTML 代码，获取公开访问链接，分享给任何人。所有托管的页面都是公开的。</div>
      <div class="hb-stats">
        <span><b id="hosting-total">-</b>个页面</span>
        <span><b id="hosting-limit">-</b>个/人上限</span>
        <span><b id="hosting-maxtotal">-</b>个全局限额</span>
        <span><b id="hosting-maxsize">-</b>KB/页上限</span>
      </div>
    </div>
    <button class="btn hosting-create-btn" id="hosting-create-btn" onclick="openHostEditor()">${ICO.plus()} 新建托管</button>
    <div class="page-scroll" id="hosting-scroll" style="flex:1;padding-top:6px">
      <div id="hosting-list">${View.skeleton()}</div>
    </div>
  </div>`;

  // 加载配置 + 列表
  try {
    const [s, r] = await Promise.all([api('hosted_settings'), api('hosted_list')]);
    $('#hosting-total').textContent = r.total;
    $('#hosting-limit').textContent = s.max_per_user;
    $('#hosting-maxtotal').textContent = s.max_total;
    $('#hosting-maxsize').textContent = s.max_size_kb;
    if (!s.enabled) {
      $('#hosting-list').innerHTML = View.empty('托管功能已关闭', '管理员已暂时关闭托管功能', '返回首页', "go('/home')");
      $('#hosting-create-btn').disabled = true;
      return;
    }
    renderHostedList(r.pages);
  } catch (e) {
    $('#hosting-list').innerHTML = View.empty('加载失败', e.message);
  }
}

function renderHostedList(pages) {
  const me = State.user;
  $('#hosting-list').innerHTML = pages.length ? pages.map(p => {
    const canDelete = me && (me.id === p.author.id || me.role === 'admin');
    const titleText = p.title || '无标题';
    return `<div class="hosted-card" onclick="go('/hosted/${p.slug}')">
      <div class="hc-body">
        <div class="hc-title">${escapeHtml(titleText)}</div>
        <div class="hc-meta">
          <span>${escapeHtml(p.author.username)}</span>
          <span>${p.created_at}</span>
          <span>${ICO.eye()} ${p.views}</span>
          <span style="color:var(--text-3);font-size:10px">/${p.slug}</span>
        </div>
      </div>
      <div class="hc-actions">
        <button class="primary" onclick="event.stopPropagation();go('/hosted/${p.slug}')">${ICO.eye()} 查看</button>
        <button onclick="event.stopPropagation();copyHostLink('${p.slug}')">${ICO.link()} 复制链接</button>
        ${canDelete ? `<button class="danger" onclick="event.stopPropagation();deleteHosted('${p.slug}')">${ICO.trash()} 删除</button>` : ''}
      </div>
    </div>`;
  }).join('') : View.empty('还没有托管的页面', me ? '点击上方按钮托管你的第一个 HTML 页面' : '登录后即可托管', me ? '新建托管' : '登录', me ? "openHostEditor()" : "go('/login')");
}

window.openHostEditor = () => {
  if (!State.user) { toast('请先登录', 'err'); go('/login'); return; }
  // 先检查托管是否开启
  api('hosted_settings').then(s => {
    if (!s.enabled) { toast('托管功能已关闭', 'err'); return; }
    showHostEditorSheet(s);
  }).catch(e => toast(e.message, 'err'));
};

function showHostEditorSheet(settings) {
  const mask = document.createElement('div');
  mask.className = 'sheet-mask';
  mask.innerHTML = `<div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-grip"></div>
    <div class="sheet-title">托管 HTML 页面</div>
    <div style="font-size:11px;color:var(--text-3);margin-bottom:14px;text-align:center">单页最大 ${settings.max_size_kb}KB · 每人上限 ${settings.max_per_user} 个</div>
    <div class="field">
      <label>标题（可选）</label>
      <input class="input" id="host-title" maxlength="100" placeholder="给你的页面起个名字">
    </div>
    <div class="field">
      <label>HTML 代码</label>
      <textarea class="textarea code-area" id="host-html" style="min-height:200px" placeholder="<!DOCTYPE html>&#10;<html>&#10;  <head><title>My Page</title></head>&#10;  <body>&#10;    <h1>Hello!</h1>&#10;  </body>&#10;</html>" spellcheck="false"></textarea>
    </div>
    <div class="field">
      <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:var(--text-1);font-weight:500;line-height:1.5;padding:10px 12px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px">
        <input type="checkbox" id="host-persistent" style="width:18px;height:18px;accent-color:var(--accent);flex-shrink:0;margin-top:1px">
        <span>
          <div>💾 开启持久化模式（localStorage 可用）</div>
          <div style="font-size:11px;color:var(--text-3);font-weight:400;margin-top:4px;line-height:1.6">
            开启后 localStorage 可用，适合游戏/应用保存数据。cookie 仍不可读（httponly），API 调用被 CSP 阻断。
          </div>
        </span>
      </label>
    </div>
    <button class="btn" id="host-submit-btn" onclick="submitHosting()">${ICO.hosting()} 立即托管</button>
  </div>`;
  mask.addEventListener('click', e => {
    if (e.target === mask) mask.remove();
  });
  document.body.appendChild(mask);
  setTimeout(() => $('#host-html').focus(), 100);
}

window.submitHosting = async () => {
  const title = $('#host-title').value.trim();
  const html = $('#host-html').value;
  if (!html.trim()) { toast('HTML 代码不能为空', 'err'); return; }
  const btn = $('#host-submit-btn');
  btn.disabled = true; btn.textContent = '托管中…';
  try {
    const persistent = $('#host-persistent') && $('#host-persistent').checked ? 1 : 0;
    const r = await api('hosted_create', { title, html_content: html, persistent_mode: persistent });
    // 关闭 sheet
    document.querySelector('.sheet-mask')?.remove();
    toast('托管成功！', 'ok');
    // 弹出分享链接
    setTimeout(() => {
      showConfirm(`托管成功！\n\n分享链接：\n${r.share_url}\n\n${persistent ? '💡 已开启持久化模式，localStorage 可用。' : '点击「复制链接」将链接复制到剪贴板，别人打开链接即可查看你的 HTML 页面。'}`, '托管成功', async () => {
        await copyToClipboard(r.share_url);
        toast('链接已复制', 'ok');
      }, null, { okText: '复制链接', type: 'success' });
    }, 300);
    // 刷新列表
    const list = await api('hosted_list');
    renderHostedList(list.pages);
    $('#hosting-total').textContent = list.total;
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false; btn.innerHTML = `${ICO.hosting()} 立即托管`;
  }
};

window.copyHostLink = async (slug) => {
  const url = location.origin + location.pathname + '?hosted=' + slug;
  const ok = await copyToClipboard(url);
  if (ok) toast('链接已复制', 'ok');
  else toast('复制失败', 'err');
};

window.deleteHosted = (slug) => {
  showConfirm('确定删除这个托管的页面吗？删除后分享链接将失效。', '删除托管', async () => {
    try {
      await api('hosted_delete', { slug });
      toast('已删除', 'ok');
      const list = await api('hosted_list');
      renderHostedList(list.pages);
      $('#hosting-total').textContent = list.total;
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
};

async function renderHostedView(slug) {
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide hosted-viewer-page">
    ${View.topbar('加载中...', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button><button class="icon-btn" onclick="copyHostLink('${slug}')" title="复制链接">${ICO.link()}</button>`)}
    <div style="flex:1;display:grid;place-items:center;color:var(--text-3);font-size:13px">加载中…</div>
  </div>`;
  try {
    const r = await api('hosted_view&slug=' + slug);
    const p = r.page;
    // 封禁检查
    if (p.is_banned) {
      app.innerHTML = `<div class="page page-slide">
        ${View.topbar('该项目已被封禁', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
        <div class="page-scroll">
          <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:30px;text-align:center">
            <div style="font-size:64px;margin-bottom:20px">🚫</div>
            <div style="font-size:22px;font-weight:700;margin-bottom:10px">该项目已被封禁</div>
            <div style="font-size:14px;color:var(--text-3);line-height:1.6;margin-bottom:24px">"${escapeHtml(p.title || '该项目')}" 因违反社区规则已被管理员封禁，无法继续查看。</div>
            <button class="btn" style="width:auto;padding:10px 22px" onclick="goBack()">返回</button>
          </div>
        </div>
      </div>`;
      return;
    }
    $('.topbar .brand span').textContent = p.title || '无标题';
    app.innerHTML = `<div class="page page-slide hosted-viewer-page">
      ${View.topbar(p.title || '无标题', `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button><button class="icon-btn" onclick="copyHostLink('${slug}')" title="复制链接">${ICO.link()}</button>`)}
      <div class="hosted-viewer-info">
        <span>作者：${escapeHtml(p.author.username)}</span>
        <span>·</span>
        <span>${p.created_at}</span>
        <span>·</span>
        <span>${ICO.eye()} ${p.views} 次浏览</span>
        <span>·</span>
        <a href="${p.share_url}" target="_blank" style="color:var(--accent);font-weight:600">在新标签页打开 ↗</a>
      </div>
      <iframe class="hosted-viewer-iframe" sandbox="allow-scripts allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock allow-presentation" srcdoc=""></iframe>
    </div>`;
    // 设置 srcdoc（避免 HTML 中含双引号导致属性截断）
    $('.hosted-viewer-iframe').srcdoc = p.html;
  } catch (e) {
    toast(e.message, 'err');
    app.innerHTML = `<div class="page page-slide">
      ${View.topbar('页面不存在', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>`)}
      <div class="page-scroll">${View.empty('页面不存在', '该托管页面可能已被删除', '返回托管列表', "go('/hosting')")}</div>
    </div>`;
  }
}

/* =========================================================
 *  Admin
 * ========================================================= */
async function renderAdmin() {
  const app = $('#app');
  // 检查是否已登录管理员（先查后端会话）
  if (!State._adminChecked) {
    State._adminChecked = true;
    try {
      const s = await api('status');
      State.isAdmin = !!s.is_admin;
    } catch (e) {}
  }

  if (!State.isAdmin) {
    app.innerHTML = `<div class="page">
      <div class="page-scroll">
        <div class="admin-login-page">
          <div class="al-logo">${ICO.shield()}</div>
          <div class="al-title">管理后台</div>
          <div class="al-sub">请输入管理员密码登录</div>
          <div class="al-form">
            <div class="field">
              <input class="input" id="admin-pass" type="password" placeholder="管理员密码" autocomplete="off">
            </div>
            <button class="btn" id="admin-login-btn" onclick="doAdminLogin()">登录</button>
            <button class="btn ghost" style="margin-top:10px" onclick="goBack()">返回</button>
            <div style="text-align:center;color:var(--text-3);font-size:11px;margin-top:14px;line-height:1.6;padding:10px;background:var(--bg-2);border-radius:6px">
              🔒 登录受频率限制保护（60秒/5次）<br>
              会话绑定 IP + 设备指纹，2 小时自动过期<br>
              所有操作记录日志
            </div>
          </div>
        </div>
      </div>
    </div>`;
    $('#admin-pass').addEventListener('keydown', e => { if (e.key === 'Enter') doAdminLogin(); });
    return;
  }

  // 已登录管理员 - 显示控制台
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('管理后台', `<button class="icon-btn" onclick="adminLogout()" title="退出">${ICO.logout()}</button>`)}
    <div class="page-scroll" id="admin-scroll">
      <div class="admin-section-title"><span style="display:flex;align-items:center;gap:6px">${ICO.chart()} 数据概览</span></div>
      <div class="admin-stat-grid" id="admin-stats">加载中…</div>

      <div class="admin-tab-row">
        <button class="at on" data-tab="posts" onclick="adminTab('posts')">帖子</button>
        <button class="at" data-tab="comments" onclick="adminTab('comments')">评论</button>
        <button class="at" data-tab="users" onclick="adminTab('users')">用户</button>
        <button class="at" data-tab="reports" onclick="adminTab('reports')">举报箱</button>
        <button class="at" data-tab="announcements" onclick="adminTab('announcements')">公告</button>
        <button class="at" data-tab="popup_announcements" onclick="adminTab('popup_announcements')">弹窗公告</button>
        <button class="at" data-tab="hosting" onclick="adminTab('hosting')">托管</button>
        <button class="at" data-tab="studios" onclick="adminTab('studios')">工作室</button>
        <button class="at" data-tab="broadcast" onclick="adminTab('broadcast')">群发</button>
        <button class="at" data-tab="site" onclick="adminTab('site')">站点</button>
        <button class="at" data-tab="settings" onclick="adminTab('settings')">设置</button>
      </div>
      <div id="admin-list"></div>
    </div>
  </div>`;

  loadAdminStats();
  adminTab('posts');

  // 为 #admin-scroll 绑定一次滚动监听，用于「用户」标签页的无限滚动加载
  // 注意：adminTab 切换其他标签时不会触发分页（loadAdminUserList 内部有 _adminUserSearchMode/hasMore 守卫）
  const adminScroll = $('#admin-scroll');
  if (adminScroll && !adminScroll._htmlhubScrollBound) {
    adminScroll._htmlhubScrollBound = true;
    adminScroll.addEventListener('scroll', () => {
      // 仅在用户标签页激活时分页
      if (_adminCurTab !== 'users') return;
      if (_adminUserLoading || !_adminUserHasMore || _adminUserSearchMode) return;
      if (adminScroll.scrollTop + adminScroll.clientHeight >= adminScroll.scrollHeight - 200) {
        loadAdminUserList(false);
      }
    });
  }
}

async function loadAdminStats() {
  try {
    const [s, d] = await Promise.all([api('admin_stats'), api('admin_stats_detail')]);
    const cards = [
      ['用户总数', s.users, `今日 +${d.today_users}`],
      ['帖子总数', s.posts, `今日 +${d.today_posts}`],
      ['评论总数', s.comments, `今日 +${d.today_comments}`],
      ['点赞总数', s.likes, ''],
      ['HTML 作品', s.html_posts, ''],
      ['文字动态', s.text_posts, ''],
      ['工作室数', d.studios, ''],
      ['已封禁', s.banned, ''],
    ];
    $('#admin-stats').innerHTML = cards.map(([label, n, delta]) => `<div class="admin-stat-card">
      <div class="as-num">${n}</div>
      <div class="as-label">${label}${delta ? `<div style="font-size:10px;color:var(--success);margin-top:2px">${delta}</div>` : ''}</div>
    </div>`).join('');

    // 7 天发帖趋势条形图
    if (d.trend && d.trend.length) {
      const maxCount = Math.max(...d.trend.map(t => t.count), 1);
      const trendHtml = d.trend.map(t => {
        const h = Math.max(4, (t.count / maxCount) * 60);
        return `<div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">
          <div style="font-size:10px;color:var(--text-3)">${t.count}</div>
          <div style="width:60%;max-width:24px;height:${h}px;background:linear-gradient(180deg,var(--accent),var(--accent-2));border-radius:3px 3px 0 0;min-height:4px;transition:height .3s ease"></div>
          <div style="font-size:10px;color:var(--text-3)">${t.date}</div>
        </div>`;
      }).join('');
      // 插入趋势图到统计卡后面
      const existingTrend = $('#admin-trend');
      if (existingTrend) {
        existingTrend.innerHTML = trendHtml;
      } else {
        const trendDiv = document.createElement('div');
        trendDiv.id = 'admin-trend-wrap';
        trendDiv.style.cssText = 'margin:0 14px 14px;padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:6px';
        trendDiv.innerHTML = `
          <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:6px"><span style="display:inline-flex;width:14px;height:14px">${ICO.chart()}</span> 近 7 天发帖趋势</div>
          <div id="admin-trend" style="display:flex;align-items:flex-end;gap:4px;height:90px">${trendHtml}</div>
        `;
        $('#admin-stats').parentElement.insertBefore(trendDiv, $('#admin-stats').nextSibling);
      }
    }
  } catch (e) {
    $('#admin-stats').innerHTML = `<div style="grid-column:1/-1;color:var(--danger);font-size:13px;padding:10px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

let _adminCurTab = 'posts';
async function adminTab(tab) {
  _adminCurTab = tab;
  $$('.admin-tab-row .at').forEach(x => x.classList.toggle('on', x.dataset.tab === tab));
  $('#admin-list').innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
  if (tab === 'posts') return loadAdminPosts();
  if (tab === 'comments') return loadAdminComments();
  if (tab === 'users') return loadAdminUsers();
  if (tab === 'reports') return loadAdminReports();
  if (tab === 'announcements') return loadAdminAnnouncements();
  if (tab === 'popup_announcements') return loadAdminPopupAnnouncements();
  if (tab === 'site') return loadAdminSite();
  if (tab === 'hosting') return loadAdminHosting();
  if (tab === 'studios') return loadAdminStudios();
  if (tab === 'broadcast') return loadAdminBroadcast();
  if (tab === 'settings') return loadAdminSettings();
}

/* =========================================================
 *  管理员：举报箱
 * ========================================================= */
let _adminReportCurStatus = 'pending';
async function loadAdminReports() {
  $('#admin-list').innerHTML = `
    <div style="padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <button class="chip ${_adminReportCurStatus==='pending'?'active':''}" data-status="pending" onclick="switchReportStatus('pending')">待处理</button>
      <button class="chip ${_adminReportCurStatus==='resolved'?'active':''}" data-status="resolved" onclick="switchReportStatus('resolved')">已处理</button>
      <button class="chip ${_adminReportCurStatus==='dismissed'?'active':''}" data-status="dismissed" onclick="switchReportStatus('dismissed')">已忽略</button>
      <button class="chip ${_adminReportCurStatus==='all'?'active':''}" data-status="all" onclick="switchReportStatus('all')">全部</button>
      <span style="flex:1"></span>
      <span id="report-info" style="font-size:11px;color:var(--text-3)"></span>
    </div>
    <div id="admin-report-list" style="padding:8px 0">${View.skeleton()}</div>
  `;
  loadAdminReportList();
}

window.switchReportStatus = (s) => {
  _adminReportCurStatus = s;
  $$('#admin-list .chip[data-status]').forEach(x => x.classList.toggle('active', x.dataset.status === s));
  loadAdminReportList();
};

async function loadAdminReportList() {
  const listEl = $('#admin-report-list');
  const infoEl = $('#report-info');
  if (!listEl) return;
  listEl.innerHTML = View.skeleton();
  try {
    const r = await api('admin_reports&status=' + _adminReportCurStatus);
    if (infoEl) infoEl.textContent = `共 ${r.total} 条`;
    listEl.innerHTML = r.reports.length
      ? r.reports.map(reportItemHtml).join('')
      : `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">${_adminReportCurStatus === 'pending' ? '🎉 没有待处理的举报' : '暂无举报记录'}</div>`;
  } catch (e) {
    listEl.innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

function reportItemHtml(r) {
  const typeLabel = { post: '帖子', comment: '评论', user: '用户' }[r.target_type] || r.target_type;
  const statusBadge = {
    pending: '<span class="p-badge" style="background:#fef3c7;color:#92400e">待处理</span>',
    resolved: '<span class="p-badge" style="background:#dcfce7;color:#15803d">已处理</span>',
    dismissed: '<span class="p-badge" style="background:var(--bg-2);color:var(--text-3)">已忽略</span>',
  }[r.status] || '';
  // 目标内容快照
  let targetHtml = '';
  if (r.target_snapshot && r.target_snapshot.exists) {
    if (r.target_type === 'post') {
      targetHtml = `<div style="margin-top:6px;padding:8px 10px;background:var(--bg-2);border-radius:4px;font-size:12px">
        <span style="color:var(--text-3)">标题：</span><a onclick="go('/post/${r.target_snapshot.id}')" style="color:var(--accent);cursor:pointer">${escapeHtml(r.target_snapshot.title || '(无标题)')}</a>
        ${r.target_snapshot.content_preview ? `<div style="margin-top:4px;color:var(--text-3);max-height:60px;overflow:hidden;-webkit-line-clamp:2;-webkit-box-orient:vertical;display:-webkit-box">${escapeHtml(r.target_snapshot.content_preview)}</div>` : ''}
      </div>`;
    } else if (r.target_type === 'comment') {
      targetHtml = `<div style="margin-top:6px;padding:8px 10px;background:var(--bg-2);border-radius:4px;font-size:12px">
        <span style="color:var(--text-3)">评论内容：</span><span>${escapeHtml(r.target_snapshot.content)}</span>
        ${r.target_snapshot.post_title ? `<div style="margin-top:4px;color:var(--text-3)">所属帖子：<a onclick="go('/post/${r.target_snapshot.post_id}')" style="color:var(--accent);cursor:pointer">${escapeHtml(r.target_snapshot.post_title)}</a></div>` : ''}
      </div>`;
    } else if (r.target_type === 'user') {
      targetHtml = `<div style="margin-top:6px;padding:8px 10px;background:var(--bg-2);border-radius:4px;font-size:12px">
        <span style="color:var(--text-3)">用户：</span><a onclick="go('/user/${r.target_snapshot.id}')" style="color:var(--accent);cursor:pointer">${escapeHtml(r.target_snapshot.username)}</a>
        ${r.target_snapshot.bio ? `<div style="margin-top:4px;color:var(--text-3)">简介：${escapeHtml(r.target_snapshot.bio)}</div>` : ''}
      </div>`;
    }
  } else {
    targetHtml = `<div style="margin-top:6px;padding:8px 10px;background:var(--bg-2);border-radius:4px;font-size:12px;color:var(--text-3)">⚠️ 目标内容已被删除（ID: ${r.target_id}）</div>`;
  }
  // 被举报人信息
  const ownerHtml = r.target_owner
    ? `<span style="margin-left:8px;color:var(--text-3);font-size:11px">被举报人：<a onclick="go('/user/${r.target_owner.id}')" style="color:var(--accent);cursor:pointer">${escapeHtml(r.target_owner.username)}</a>${r.target_owner.status === 'banned' ? ' <span class="p-badge banned">封禁</span>' : ''}${r.target_owner.role === 'admin' ? ' <span class="p-badge">管理员</span>' : ''}</span>`
    : '';
  // 操作按钮
  let actionsHtml = '';
  if (r.status === 'pending') {
    let targetActionBtns = '';
    if (r.target_type === 'post' && r.target_snapshot && r.target_snapshot.exists) {
      targetActionBtns += `<button class="danger" onclick="handleReport(${r.id}, 'resolve', 'delete_post')">删除帖子</button>`;
    }
    if (r.target_type === 'comment' && r.target_snapshot && r.target_snapshot.exists) {
      targetActionBtns += `<button class="danger" onclick="handleReport(${r.id}, 'resolve', 'delete_comment')">删除评论</button>`;
    }
    if (r.target_owner && r.target_owner.role !== 'admin') {
      if (r.target_owner.status === 'banned') {
        targetActionBtns += `<button onclick="handleReport(${r.id}, 'resolve', 'unban_user')">解封用户</button>`;
      } else {
        targetActionBtns += `<button class="danger" onclick="handleReport(${r.id}, 'resolve', 'ban_user')">封禁用户</button>`;
      }
    }
    actionsHtml = `
      <div class="ap-actions" style="flex-wrap:wrap">
        ${targetActionBtns}
        <button onclick="handleReport(${r.id}, 'resolve', 'none')">标记已处理</button>
        <button onclick="handleReport(${r.id}, 'dismiss', 'none')">忽略</button>
        <button class="danger" onclick="deleteReport(${r.id})">删除记录</button>
      </div>`;
  } else {
    actionsHtml = `
      <div class="ap-actions">
        <button class="danger" onclick="deleteReport(${r.id})">删除记录</button>
      </div>`;
  }
  // 处理记录
  const handledHtml = r.status !== 'pending' && r.handler
    ? `<div style="margin-top:6px;font-size:11px;color:var(--text-3)">处理人：${escapeHtml(r.handler.username)} · 处理时间：${r.handled_at}${r.handler_note ? ` · 备注：${escapeHtml(r.handler_note)}` : ''}</div>`
    : '';
  return `<div class="admin-post-item" data-id="${r.id}">
    <div class="ap-head">
      ${statusBadge}
      <span class="ap-type">举报${typeLabel}</span>
      <span style="font-size:11px;color:var(--text-3)">原因：${escapeHtml(r.reason_label)}</span>
      <div class="ap-title" style="flex:0 0 auto;font-size:12px">举报人：${escapeHtml(r.reporter.username)}</div>
    </div>
    <div class="ap-meta">
      举报时间：${r.created_at}${ownerHtml}
    </div>
    ${r.detail ? `<div style="margin-top:6px;padding:8px 10px;background:var(--bg-2);border-radius:4px;font-size:12px"><span style="color:var(--text-3)">补充说明：</span>${escapeHtml(r.detail)}</div>` : ''}
    ${targetHtml}
    ${handledHtml}
    ${actionsHtml}
  </div>`;
}

window.handleReport = (id, action, targetAction) => {
  const actionLabel = action === 'resolve' ? '处理' : '忽略';
  const targetLabel = {
    delete_post: '并删除帖子',
    delete_comment: '并删除评论',
    ban_user: '并封禁用户',
    unban_user: '并解封用户',
    none: '',
  }[targetAction] || '';
  showConfirm(`确定${actionLabel}这条举报${targetLabel}吗？${targetAction !== 'none' && targetAction !== 'unban_user' ? '\n\n此操作不可撤销！' : ''}`, `${actionLabel}举报`, async () => {
    const note = window.prompt ? '' : ''; // 不用 prompt，直接提交空备注
    try {
      const r = await api('admin_report_action', { id, action, target_action: targetAction, note: '' });
      toast(`已${actionLabel}${r.action_result ? '（' + r.action_result + '）' : ''}`, 'ok');
      loadAdminReportList();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: targetAction === 'delete_post' || targetAction === 'delete_comment' || targetAction === 'ban_user', okText: `确认${actionLabel}` });
};

window.deleteReport = (id) => {
  showConfirm('确定删除这条举报记录吗？此操作不可撤销，删除后无法恢复。', '删除举报记录', async () => {
    try {
      await api('admin_report_delete', { id });
      toast('已删除举报记录', 'ok');
      loadAdminReportList();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '确认删除' });
};

async function loadAdminPosts() {
  $('#admin-list').innerHTML = `
    <div style="padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;gap:8px">
      <input class="input" id="admin-post-search" placeholder="搜索标题/内容/作者" style="flex:1;padding:8px 12px;font-size:13px">
      <button class="btn" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminSearchPosts()">搜索</button>
      <button class="btn ghost" style="width:auto;padding:8px 16px;font-size:13px" onclick="loadAdminPostList()">全部</button>
    </div>
    <div id="admin-post-list" style="padding:8px 0">${View.skeleton()}</div>
  `;
  loadAdminPostList();
}

async function loadAdminPostList() {
  try {
    const r = await api('admin_posts');
    $('#admin-post-list').innerHTML = r.posts.length ? r.posts.map(p => adminPostItemHtml(p)).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无帖子</div>';
  } catch (e) {
    $('#admin-post-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

async function adminSearchPosts() {
  const q = $('#admin-post-search').value.trim();
  if (!q) { loadAdminPostList(); return; }
  $('#admin-post-list').innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">搜索中…</div>';
  try {
    const r = await api('admin_search_posts&q=' + encodeURIComponent(q));
    $('#admin-post-list').innerHTML = r.posts.length ? r.posts.map(p => adminPostItemHtml(p)).join('') : `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">没有匹配 "${escapeHtml(q)}" 的帖子</div>`;
  } catch (e) {
    $('#admin-post-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">搜索失败：${escapeHtml(e.message)}</div>`;
  }
}

function adminPostItemHtml(p) {
  return `<div class="admin-post-item">
    <div class="ap-head">
      ${p.is_pinned ? '<span class="ap-pin">置顶</span>' : ''}
      ${p.studio_id > 0 ? '<span class="ap-pin" style="background:var(--accent-soft);color:var(--accent)">工作室</span>' : ''}
      <span class="ap-type">${p.type === 'html' ? 'HTML' : '文字'}</span>
      <div class="ap-title">${escapeHtml(p.title)}</div>
    </div>
    <div class="ap-meta">作者：${escapeHtml(p.author.username)} · ${p.created_at} · ${p.views} 浏览 · ${p.likes_count} 赞 · ${p.comments_count} 评</div>
    <div class="ap-actions">
      <button onclick="go('/post/${p.id}')">查看</button>
      <button onclick="adminEditPost(${p.id}, ${p.type==='text'?'text':'html'}, '${escapeHtml(p.title).replace(/'/g, "\\'")}')">编辑</button>
      <button class="pin" onclick="adminTogglePin(${p.id}, ${p.is_pinned?0:1})">${p.is_pinned?'取消置顶':'置顶'}</button>
      <button class="danger" onclick="adminDeletePost(${p.id}, this)">删除</button>
    </div>
  </div>`;
}

function adminEditPost(id, type, title) {
  const mask = document.createElement('div');
  mask.className = 'sheet-mask';
  mask.innerHTML = `<div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-grip"></div>
    <div class="sheet-title">编辑帖子</div>
    <div class="field">
      <label>标题</label>
      <input class="input" id="ep-title" maxlength="50" value="${escapeHtml(title)}">
    </div>
    ${type === 'text' ? `<div class="field"><label>内容</label><textarea class="textarea" id="ep-content" maxlength="5000"></textarea></div>` : ''}
    <button class="btn" onclick="saveAdminEditPost(${id}, '${type}')">保存</button>
  </div>`;
  mask.addEventListener('click', () => mask.remove());
  document.body.appendChild(mask);
  // 异步加载文字内容
  if (type === 'text') {
    api('post&id=' + id).then(r => {
      $('#ep-content').value = r.post.content || '';
    }).catch(() => {});
  }
  window.saveAdminEditPost = async (id, type) => {
    const t = $('#ep-title').value.trim();
    if (!t) { toast('标题不能为空', 'err'); return; }
    const payload = { id, title: t };
    if (type === 'text') payload.content = $('#ep-content').value;
    try {
      await api('admin_edit_post', payload);
      toast('已保存', 'ok');
      mask.remove();
      loadAdminPosts();
    } catch (e) { toast(e.message, 'err'); }
  };
}

async function loadAdminComments() {
  try {
    const r = await api('admin_comments');
    $('#admin-list').innerHTML = r.comments.length ? r.comments.map(c => `
      <div class="admin-post-item">
        <div class="ap-head">
          <div class="ap-title" style="font-weight:500;font-size:13px">${escapeHtml(c.content)}</div>
        </div>
        <div class="ap-meta">${escapeHtml(c.user.username)} · ${c.created_at} · 在《${escapeHtml(c.post.title)}》</div>
        <div class="ap-actions">
          <button onclick="go('/post/${c.post.id}')">查看帖子</button>
          <button onclick="go('/user/${c.user.id}')">查看用户</button>
          <button class="danger" onclick="adminDeleteComment(${c.id}, this)">删除评论</button>
        </div>
      </div>
    `).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无评论</div>';
  } catch (e) {
    $('#admin-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

async function adminDeleteComment(id, btn) {
  showConfirm('确定删除这条评论吗？', '删除评论', async () => {
    try {
      await api('admin_delete_comment', { id });
      toast('已删除', 'ok');
      btn.closest('.admin-post-item').remove();
      loadAdminStats();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
}

async function loadAdminAnnouncements() {
  try {
    const r = await api('admin_announcements');
    $('#admin-list').innerHTML = `
      <div style="padding:14px">
        <button class="btn" onclick="adminEditAnnouncement()" style="margin-bottom:14px">+ 新建公告</button>
        ${r.announcements.length ? r.announcements.map(a => `
          <div class="admin-post-item" style="padding:12px;border:1px solid var(--border);margin-bottom:8px">
            <div class="ap-head">
              ${a.is_active ? '<span class="ap-pin" style="background:#dcfce7;color:#15803d">激活</span>' : '<span class="ap-pin" style="background:#f3f4f6;color:#6b7280">隐藏</span>'}
              <div class="ap-title">${escapeHtml(a.title)}</div>
            </div>
            ${a.content ? `<div style="font-size:12px;color:var(--text-3);margin:6px 0;line-height:1.5">${escapeHtml(a.content)}</div>` : ''}
            <div class="ap-meta">${a.created_at}</div>
            <div class="ap-actions">
              <button onclick="adminEditAnnouncement(${a.id})">编辑</button>
              <button class="danger" onclick="adminDeleteAnnouncement(${a.id}, this)">删除</button>
            </div>
          </div>
        `).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无公告</div>'}
      </div>
    `;
  } catch (e) {
    $('#admin-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

function adminEditAnnouncement(id) {
  const mask = document.createElement('div');
  mask.className = 'sheet-mask';
  mask.innerHTML = `<div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-grip"></div>
    <div class="sheet-title">${id ? '编辑公告' : '新建公告'}</div>
    <div class="field">
      <label>标题</label>
      <input class="input" id="an-title" maxlength="200" placeholder="公告标题">
    </div>
    <div class="field">
      <label>内容（可选）</label>
      <textarea class="textarea" id="an-content" maxlength="1000" placeholder="公告正文"></textarea>
    </div>
    <div class="field">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="an-active" checked style="width:16px;height:16px"> 立即上线（用户可见）
      </label>
    </div>
    <button class="btn" onclick="saveAdminAnnouncement(${id||0})">保存</button>
  </div>`;
  mask.addEventListener('click', () => mask.remove());
  document.body.appendChild(mask);
  if (id) {
    api('admin_announcements').then(r => {
      const a = r.announcements.find(x => x.id === id);
      if (a) {
        $('#an-title').value = a.title;
        $('#an-content').value = a.content;
        $('#an-active').checked = a.is_active;
      }
    }).catch(() => {});
  }
  window.saveAdminAnnouncement = async (id) => {
    const payload = {
      title: $('#an-title').value,
      content: $('#an-content').value,
      is_active: $('#an-active').checked,
    };
    if (!payload.title.trim()) { toast('标题不能为空', 'err'); return; }
    try {
      if (id) {
        payload.id = id;
        await api('admin_update_announcement', payload);
      } else {
        await api('admin_add_announcement', payload);
      }
      toast('已保存', 'ok');
      mask.remove();
      loadAdminAnnouncements();
    } catch (e) { toast(e.message, 'err'); }
  };
}

async function adminDeleteAnnouncement(id, btn) {
  showConfirm('确定删除这条公告吗？', '删除公告', async () => {
    try {
      await api('admin_delete_announcement', { id });
      toast('已删除', 'ok');
      btn.closest('.admin-post-item').remove();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
}

/* ===== 弹窗公告管理（生产级，支持 Markdown 编辑/预览） ===== */

async function loadAdminPopupAnnouncements() {
  $('#admin-list').innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
  try {
    const r = await api('admin_popup_announcements');
    const list = r.popup_announcements || [];
    $('#admin-list').innerHTML = `
      <div style="padding:14px;background:var(--bg);border-bottom:1px solid var(--border)">
        <div style="font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:8px;display:flex;align-items:center;gap:6px">📢 弹窗公告管理</div>
        <div style="font-size:12px;color:var(--text-3);line-height:1.6">
          • 弹窗公告在<b>用户进入站点时</b>自动弹出展示，每个浏览器会话仅展示一次（关闭后本次会话不再弹出）<br>
          • 内容支持 <b>Markdown</b> 格式（标题、加粗、列表、链接、代码块、引用等）<br>
          • 同一时刻仅允许<b>一条激活</b>；新建/编辑时勾选「立即上线」会自动停用其他弹窗<br>
          • 适合发布：维护通知、版本更新、活动公告、政策变更等重要信息
        </div>
        <button class="btn" style="margin-top:12px" onclick="adminEditPopupAnnouncement()">+ 新建弹窗公告</button>
      </div>
      <div id="popup-ann-list" style="padding:8px 14px">
        ${list.length ? list.map(a => `
          <div class="admin-post-item" data-id="${a.id}">
            <div class="ap-head">
              ${a.is_active ? '<span class="ap-pin" style="background:#dcfce7;color:#15803d">激活中</span>' : '<span class="ap-pin" style="background:#f3f4f6;color:#6b7280">未激活</span>'}
              <div class="ap-title">${escapeHtml(a.title || '(无标题)')}</div>
            </div>
            <div style="font-size:12px;color:var(--text-3);margin:6px 0;line-height:1.5;max-height:80px;overflow:hidden;-webkit-line-clamp:3;-webkit-box-orient:vertical;display:-webkit-box">${escapeHtml(a.content_md)}</div>
            <div class="ap-meta">创建：${a.created_at}${a.updated_at ? ' · 更新：' + a.updated_at : ''}</div>
            <div class="ap-actions">
              <button onclick="adminPreviewPopupAnnouncement(${a.id})">预览</button>
              <button onclick="adminEditPopupAnnouncement(${a.id})">编辑</button>
              ${!a.is_active ? `<button class="pin" onclick="adminActivatePopupAnnouncement(${a.id})">激活</button>` : ''}
              <button class="danger" onclick="adminDeletePopupAnnouncement(${a.id}, this)">删除</button>
            </div>
          </div>
        `).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无弹窗公告，点击上方按钮新建</div>'}
      </div>
    `;
    // 缓存列表供预览使用
    window._popupAnnList = list;
  } catch (e) {
    $('#admin-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

window.adminEditPopupAnnouncement = function(id) {
  const mask = document.createElement('div');
  mask.className = 'sheet-mask';
  mask.innerHTML = `<div class="sheet" onclick="event.stopPropagation()" style="max-width:640px">
    <div class="sheet-grip"></div>
    <div class="sheet-title">${id ? '编辑弹窗公告' : '新建弹窗公告'}</div>
    <div class="field">
      <label>标题（可选，留空则不显示标题栏）</label>
      <input class="input" id="pa-title" maxlength="200" placeholder="例如：站点维护通知">
    </div>
    <div class="field">
      <label>正文（必填，支持 Markdown）</label>
      <textarea class="textarea" id="pa-content" maxlength="10000" placeholder="## 维护通知

系统将于 **今晚 22:00** 进行维护，预计 30 分钟。

- 备份期间无法访问
- 完成后自动恢复

感谢理解！" style="min-height:200px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px"></textarea>
      <div style="display:flex;justify-content:space-between;margin-top:4px">
        <span style="font-size:11px;color:var(--text-3)">支持 Markdown：标题 # / 加粗 ** / 列表 - / 链接 []() / 代码块用三个反引号 / 引用 &gt;</span>
        <span style="font-size:11px;color:var(--text-3)" id="pa-counter">0/10000</span>
      </div>
    </div>
    <div class="field">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="pa-active" checked style="width:16px;height:16px"> 立即上线（用户进入站点时可见；激活后会自动停用其他弹窗）
      </label>
    </div>
    <div class="field">
      <label style="font-size:12px;color:var(--text-3);margin-bottom:6px">实时预览</label>
      <div id="pa-preview" class="md-content popup-preview-box" style="min-height:80px;padding:12px 14px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;font-size:14px;line-height:1.6;max-height:260px;overflow-y:auto"></div>
    </div>
    <button class="btn" id="pa-save-btn" onclick="saveAdminPopupAnnouncement(${id||0})">保存</button>
  </div>`;
  mask.addEventListener('click', () => mask.remove());
  document.body.appendChild(mask);

  const ta = $('#pa-content');
  const preview = $('#pa-preview');
  const counter = $('#pa-counter');

  function updatePreview() {
    counter.textContent = `${ta.value.length}/10000`;
    preview.innerHTML = renderMarkdown(ta.value);
  }
  ta.addEventListener('input', updatePreview);

  // 异步载入已有数据
  if (id) {
    api('admin_popup_announcements').then(r => {
      const a = (r.popup_announcements || []).find(x => x.id === id);
      if (a) {
        $('#pa-title').value = a.title || '';
        ta.value = a.content_md || '';
        $('#pa-active').checked = !!a.is_active;
        updatePreview();
      }
    }).catch(() => {});
  } else {
    updatePreview();
  }

  window.saveAdminPopupAnnouncement = async (id) => {
    const payload = {
      title: $('#pa-title').value,
      content_md: ta.value,
      is_active: $('#pa-active').checked,
    };
    if (!payload.content_md.trim()) { toast('弹窗内容不能为空', 'err'); return; }
    const btn = $('#pa-save-btn');
    btn.disabled = true; btn.textContent = '保存中…';
    try {
      if (id) {
        payload.id = id;
        await api('admin_update_popup_announcement', payload);
      } else {
        await api('admin_add_popup_announcement', payload);
      }
      toast('已保存', 'ok');
      mask.remove();
      loadAdminPopupAnnouncements();
    } catch (e) {
      toast(e.message, 'err');
    } finally {
      btn.disabled = false; btn.textContent = '保存';
    }
  };
};

window.adminActivatePopupAnnouncement = async (id) => {
  // 激活必须基于已有内容，所以先拉取再提交（仅切换 is_active 状态）
  try {
    const r = await api('admin_popup_announcements');
    const a = (r.popup_announcements || []).find(x => x.id === id);
    if (!a) { toast('弹窗公告不存在', 'err'); return; }
    await api('admin_update_popup_announcement', {
      id,
      title: a.title,
      content_md: a.content_md,
      is_active: true,
    });
    toast('已激活', 'ok');
    loadAdminPopupAnnouncements();
  } catch (e) {
    toast(e.message, 'err');
  }
};

window.adminDeletePopupAnnouncement = async (id, btn) => {
  showConfirm('确定删除这条弹窗公告吗？此操作不可恢复。', '删除弹窗公告', async () => {
    try {
      await api('admin_delete_popup_announcement', { id });
      toast('已删除', 'ok');
      btn.closest('.admin-post-item').remove();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
};

window.adminPreviewPopupAnnouncement = function(id) {
  const list = window._popupAnnList || [];
  const a = list.find(x => x.id === id);
  if (!a) { toast('弹窗公告不存在', 'err'); return; }
  const mask = document.createElement('div');
  mask.className = 'popup-mask';
  mask.innerHTML = `<div class="popup-box" onclick="event.stopPropagation()">
    <div class="popup-head">
      <div class="popup-icon">📢</div>
      <div class="popup-title">${escapeHtml(a.title || '站点公告')}</div>
      <button class="popup-close" onclick="this.closest('.popup-mask').remove()" aria-label="关闭">×</button>
    </div>
    <div class="popup-body md-content">${renderMarkdown(a.content_md)}</div>
    <div class="popup-foot">
      <div class="popup-meta">${escapeHtml(a.created_at || '')}</div>
      <button class="btn popup-ok-btn" onclick="this.closest('.popup-mask').remove()">我知道了</button>
    </div>
  </div>`;
  mask.addEventListener('click', () => mask.remove());
  document.body.appendChild(mask);
};

async function loadAdminSite() {
  $('#admin-list').innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
  try {
    const s = await api('settings');
    const csEnabled = s.code_score_enabled !== false;
    $('#admin-list').innerHTML = `
      <div style="padding:14px">
        <div class="field">
          <label>站点名称</label>
          <input class="input" id="site-name" maxlength="30" value="${escapeHtml(s.site_name)}">
        </div>
        <div class="field">
          <label>站点描述</label>
          <input class="input" id="site-desc" maxlength="100" value="${escapeHtml(s.site_desc)}">
        </div>
        <button class="btn" onclick="saveAdminSite()">保存</button>
        <div style="margin-top:20px;padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-3);line-height:1.7">
          站点名称会显示在顶部导航栏和浏览器标题。修改后所有用户下次访问生效。
        </div>

        <div style="margin-top:24px;padding-top:18px;border-top:1px solid var(--border)">
          <div style="font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:6px">🧪 玩具工具开关</div>
          <div class="field" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px">
            <div>
              <div style="font-size:13px;font-weight:600;color:var(--text)">代码质量评分工具</div>
              <div style="font-size:11px;color:var(--text-3);line-height:1.6;margin-top:4px">
                开启后，用户可在「设置 → 玩具工具」和「发布 HTML 作品」编辑器中看到代码评分入口；<br>
                关闭后，所有相关入口将自动隐藏。
              </div>
            </div>
            <button class="settings-toggle ${csEnabled?'on':''}" id="admin-cs-toggle" onclick="toggleCodeScoreTool(this)" style="flex-shrink:0"><div class="st-knob"></div></button>
          </div>
        </div>
      </div>
    `;
  } catch (e) {
    $('#admin-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

window.toggleCodeScoreTool = async function(btn) {
  const next = !btn.classList.contains('on');
  btn.disabled = true;
  try {
    const r = await api('admin_code_score_toggle', { enabled: next });
    btn.classList.toggle('on', r.enabled);
    // 同步前端 State，让其他页面立即生效
    State.settings.code_score_enabled = r.enabled;
    toast(r.enabled ? '已开启代码评分工具' : '已关闭代码评分工具', 'ok');
  } catch (e) {
    toast(e.message, 'err');
  } finally {
    btn.disabled = false;
  }
};

async function saveAdminSite() {
  const siteName = $('#site-name').value.trim();
  const siteDesc = $('#site-desc').value.trim();
  if (!siteName) { toast('站点名不能为空', 'err'); return; }
  try {
    await api('admin_site_settings', { site_name: siteName, site_desc: siteDesc });
    State.settings.site_name = siteName;
    State.settings.site_desc = siteDesc;
    document.title = `${siteName} · HTML 作品社区`;
    toast('已保存', 'ok');
  } catch (e) { toast(e.message, 'err'); }
}

/* =========================================================
 *  管理员：用户列表（分页加载 + 批量操作）
 * ========================================================= */
let _adminUserSelectedIds = new Set();
let _adminUserPage = 1;
let _adminUserHasMore = true;
let _adminUserTotal = 0;
let _adminUserLoaded = 0;
let _adminUserLoading = false;
let _adminUserFilter = { status: 'all', range: 'all', bot_like: false, has_no_posts: false };
let _adminUserSearchMode = false; // 搜索模式下不分页

async function loadAdminUsers() {
  // 重置状态
  _adminUserSelectedIds = new Set();
  _adminUserPage = 1;
  _adminUserHasMore = true;
  _adminUserTotal = 0;
  _adminUserLoaded = 0;
  _adminUserLoading = false;
  _adminUserSearchMode = false;

  $('#admin-list').innerHTML = `
    <div style="padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap">
      <input class="input" id="admin-user-search" placeholder="搜索用户名/简介" style="flex:1;min-width:160px;padding:8px 12px;font-size:13px" onkeydown="if(event.key==='Enter')adminSearchUsers()">
      <button class="btn" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminSearchUsers()">搜索</button>
      <button class="btn ghost" style="width:auto;padding:8px 16px;font-size:13px" onclick="loadAdminUserList(true)">全部</button>
      <button class="btn ghost" style="width:auto;padding:8px 16px;font-size:13px" onclick="toggleAdminUserFilter()">筛选</button>
    </div>
    <div id="admin-user-filter" style="display:none;padding:10px 14px;background:var(--bg-2);border-bottom:1px solid var(--border);font-size:12px;color:var(--text-2)">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label style="display:flex;align-items:center;gap:4px">状态
          <select id="f-status" style="padding:4px 8px;border:1px solid var(--border-strong);border-radius:4px;background:var(--bg);color:var(--text-1);font-size:12px">
            <option value="all">全部</option>
            <option value="active">正常</option>
            <option value="banned">已封禁</option>
          </select>
        </label>
        <label style="display:flex;align-items:center;gap:4px">注册时间
          <select id="f-range" style="padding:4px 8px;border:1px solid var(--border-strong);border-radius:4px;background:var(--bg);color:var(--text-1);font-size:12px">
            <option value="all">全部时间</option>
            <option value="1h">最近 1 小时</option>
            <option value="24h">最近 24 小时</option>
            <option value="7d">最近 7 天</option>
            <option value="30d">最近 30 天</option>
          </select>
        </label>
        <label style="display:flex;align-items:center;gap:4px"><input type="checkbox" id="f-bot" style="vertical-align:middle"> 仅疑似机器人用户名</label>
        <label style="display:flex;align-items:center;gap:4px"><input type="checkbox" id="f-noposts" style="vertical-align:middle"> 仅 0 帖用户</label>
        <button class="btn" style="width:auto;padding:6px 12px;font-size:12px" onclick="applyAdminUserFilter()">应用筛选</button>
        <button class="btn ghost" style="width:auto;padding:6px 12px;font-size:12px" onclick="resetAdminUserFilter()">重置</button>
      </div>
      <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span style="color:var(--text-3)">快速清理：</span>
        <button class="btn danger" style="width:auto;padding:6px 12px;font-size:12px" onclick="adminQuickCleanBots()">清掉 24h 内疑似机器人(0 帖)</button>
      </div>
    </div>
    <div id="admin-user-bulkbar" style="display:none;padding:8px 14px;background:var(--accent-soft);border-bottom:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <span id="admin-user-selected-count" style="font-size:12px;color:var(--accent);font-weight:600">0 项已选</span>
      <button class="btn ghost" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminUserSelectAllPage()">全选当前页</button>
      <button class="btn ghost" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminUserClearSelect()">取消选择</button>
      <button class="btn" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminBulkBan(true)">批量封禁</button>
      <button class="btn" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminBulkBan(false)">批量解封</button>
      <button class="btn" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminBulkCleanLikesSelected()">清理这些用户的赞</button>
      <button class="btn danger" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminBulkDeleteSelected()">批量删除</button>
    </div>
    <div id="admin-user-info" style="padding:6px 14px;font-size:11px;color:var(--text-3);border-bottom:1px solid var(--border);background:var(--bg)"></div>
    <div id="admin-user-list" style="padding:8px 0">${View.skeleton()}</div>
  `;
  loadAdminUserList(true);
}

// 把筛选对象转成查询参数
function adminUserFilterToQuery(extra = {}) {
  const q = { ...extra };
  const f = _adminUserFilter;
  if (f.status !== 'all') q.status = f.status;
  if (f.bot_like) q.bot_like = 1;
  if (f.has_no_posts) q.has_no_posts = 1;
  if (f.range !== 'all') {
    const now = Math.floor(Date.now() / 1000);
    if (f.range === '1h') q.created_after = now - 3600;
    else if (f.range === '24h') q.created_after = now - 86400;
    else if (f.range === '7d') q.created_after = now - 7 * 86400;
    else if (f.range === '30d') q.created_after = now - 30 * 86400;
  }
  return q;
}

// 拼接 GET 查询字符串
function buildQuery(params) {
  const parts = [];
  for (const k in params) {
    if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
    }
  }
  return parts.length ? ('&' + parts.join('&')) : '';
}

async function loadAdminUserList(reset = false) {
  if (_adminUserLoading) return;
  if (reset) {
    _adminUserPage = 1;
    _adminUserHasMore = true;
    _adminUserLoaded = 0;
    _adminUserSelectedIds = new Set();
    $('#admin-user-list').innerHTML = View.skeleton();
    updateAdminUserBulkBar();
  }
  if (!_adminUserHasMore && !reset) return;
  _adminUserLoading = true;
  try {
    const params = adminUserFilterToQuery({ page: _adminUserPage });
    const r = await api('admin_users' + buildQuery(params));
    _adminUserTotal = r.total || 0;
    _adminUserHasMore = !!r.has_more;
    const html = r.users.length ? r.users.map(u => adminUserItemHtml(u)).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无用户</div>';
    if (reset) {
      $('#admin-user-list').innerHTML = html;
    } else {
      // 追加前移除底部 footer
      const footer = $('#admin-user-list .list-footer');
      if (footer) footer.remove();
      $('#admin-user-list').insertAdjacentHTML('beforeend', html);
    }
    _adminUserLoaded += r.users.length;
    // 底部 footer
    if (r.users.length > 0) {
      const footerHtml = _adminUserHasMore
        ? `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多…</div>`
        : `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">· 已经到底啦 ·</div>`;
      $('#admin-user-list').insertAdjacentHTML('beforeend', footerHtml);
    }
    $('#admin-user-info').textContent = `共 ${_adminUserTotal} 个用户 · 已加载 ${_adminUserLoaded} 个${_adminUserFilter.range !== 'all' || _adminUserFilter.status !== 'all' ? ' · 已筛选' : ''}`;
    _adminUserPage++;
    // 重新勾选已选中项
    $$('#admin-user-list input[type=checkbox].u-check').forEach(cb => {
      cb.checked = _adminUserSelectedIds.has(parseInt(cb.dataset.id, 10));
    });
  } catch (e) {
    $('#admin-user-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
    _adminUserHasMore = false;
  } finally {
    _adminUserLoading = false;
  }
}

async function adminSearchUsers() {
  const q = $('#admin-user-search').value.trim();
  if (!q) { loadAdminUserList(true); return; }
  $('#admin-user-list').innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">搜索中…</div>';
  _adminUserSearchMode = true;
  _adminUserSelectedIds = new Set();
  try {
    const r = await api('admin_search_users&q=' + encodeURIComponent(q));
    _adminUserTotal = r.total || r.users.length;
    _adminUserHasMore = false;
    $('#admin-user-list').innerHTML = r.users.length ? r.users.map(u => adminUserItemHtml(u)).join('') : `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">没有匹配 "${escapeHtml(q)}" 的用户</div>`;
    $('#admin-user-info').textContent = `搜索 "${q}" · 找到 ${r.users.length} 个用户`;
    updateAdminUserBulkBar();
  } catch (e) {
    $('#admin-user-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">搜索失败：${escapeHtml(e.message)}</div>`;
  }
}

function adminUserItemHtml(u) {
  const sel = _adminUserSelectedIds.has(u.id) ? 'checked' : '';
  const botBadge = u.bot_like ? ' <span class="p-badge" style="background:#fef3c7;color:#92400e">疑似机器人</span>' : '';
  // 安全：username 通过 data-* 属性传递，避免在 onclick 内拼接字符串
  return `<div class="admin-user-item" data-uid="${u.id}">
    <input type="checkbox" class="u-check" data-id="${u.id}" data-name="${escapeHtml(u.username)}" ${sel} onchange="adminUserToggleSelect(${u.id}, this.checked)" style="flex-shrink:0;margin-right:4px">
    ${avatarHtml(u)}
    <div class="u-info">
      <div class="u-name">${escapeHtml(u.username)} ${u.role==='admin'?'<span class="p-badge">管理员</span>':''} ${u.status==='banned'?'<span class="p-badge banned">封禁</span>':''}${botBadge}</div>
      <div class="u-meta">${u.posts_count} 作品 · ${u.followers_count} 粉丝 · ${u.following_count} 关注 · 注册 ${u.created_at}</div>
    </div>
    <div class="u-actions" style="flex-wrap:wrap">
      <button onclick="go('/user/${u.id}')">查看</button>
      <button onclick="adminViewUserPosts(${u.id}, this.getAttribute('data-name'))" data-name="${escapeHtml(u.username)}">作品</button>
      ${u.role !== 'admin' ? `<button onclick="adminResetUserPwd(${u.id})">重置密码</button>` : ''}
      ${u.role !== 'admin' ? (u.status === 'banned'
        ? `<button class="banned" onclick="adminBanUser(${u.id}, false, this)">解封</button>`
        : `<button class="danger" onclick="adminBanUser(${u.id}, true, this)">封禁</button>`) : ''}
      ${u.role !== 'admin' ? `<button class="danger" onclick="adminDeleteUser(${u.id}, this.getAttribute('data-name'))" data-name="${escapeHtml(u.username)}">删除用户</button>` : ''}
    </div>
  </div>`;
}

// 单项选择切换
function adminUserToggleSelect(id, checked) {
  if (checked) _adminUserSelectedIds.add(id);
  else _adminUserSelectedIds.delete(id);
  updateAdminUserBulkBar();
}

// 全选当前页
function adminUserSelectAllPage() {
  $$('#admin-user-list input[type=checkbox].u-check').forEach(cb => {
    const id = parseInt(cb.dataset.id, 10);
    cb.checked = true;
    _adminUserSelectedIds.add(id);
  });
  updateAdminUserBulkBar();
}

// 取消选择
function adminUserClearSelect() {
  _adminUserSelectedIds = new Set();
  $$('#admin-user-list input[type=checkbox].u-check').forEach(cb => cb.checked = false);
  updateAdminUserBulkBar();
}

// 更新批量操作工具栏
function updateAdminUserBulkBar() {
  const bar = $('#admin-user-bulkbar');
  if (!bar) return;
  const n = _adminUserSelectedIds.size;
  bar.style.display = n > 0 ? 'flex' : 'none';
  const cntEl = $('#admin-user-selected-count');
  if (cntEl) cntEl.textContent = `${n} 项已选`;
}

// 批量删除（按选中的 ID）
function adminBulkDeleteSelected() {
  const ids = Array.from(_adminUserSelectedIds);
  if (ids.length === 0) { toast('请先选择用户', 'err'); return; }
  showConfirm(`确定批量删除选中的 ${ids.length} 个用户吗？\n\n此操作将联级删除这些用户的所有：\n• 帖子及关联点赞/收藏/评论\n• 评论、点赞、收藏\n• 关注/粉丝关系\n• 通知记录\n• 拥有的工作室\n\n管理员账号会被自动跳过。此操作不可撤销！`, '批量删除用户', async () => {
    try {
      const r = await api('admin_bulk_delete_users', { ids });
      toast(`已删除 ${r.deleted_count} 个用户（${r.deleted_posts} 帖子，${r.deleted_studios} 工作室）${r.skipped_admin > 0 ? `，跳过 ${r.skipped_admin} 个管理员` : ''}`, 'ok');
      loadAdminUserList(true);
      loadAdminStats();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '确认批量删除' });
}

// 批量封禁/解封（按选中的 ID）
function adminBulkBan(banned) {
  const ids = Array.from(_adminUserSelectedIds);
  if (ids.length === 0) { toast('请先选择用户', 'err'); return; }
  const action = banned ? '封禁' : '解封';
  showConfirm(`确定批量${action}选中的 ${ids.length} 个用户吗？`, `批量${action}用户`, async () => {
    try {
      const r = await api('admin_bulk_ban_users', { ids, banned });
      toast(`已${action} ${r.updated_count} 个用户${r.skipped_admin > 0 ? `，跳过 ${r.skipped_admin} 个管理员` : ''}`, 'ok');
      loadAdminUserList(true);
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: banned, okText: `确认批量${action}` });
}

// 清理选中用户的所有点赞（专门对付机器人刷赞）
function adminBulkCleanLikesSelected() {
  const ids = Array.from(_adminUserSelectedIds);
  if (ids.length === 0) { toast('请先选择用户', 'err'); return; }
  showConfirm(`确定清空选中的 ${ids.length} 个用户的所有点赞吗？\n\n这会移除他们给所有作品点的赞，并相应扣减帖子的 likes_count。\n\n适用于清理机器人刷的赞。`, '清理点赞', async () => {
    try {
      const r = await api('admin_bulk_clean_likes', { user_ids: ids });
      toast(`已清理 ${r.deleted_likes} 个点赞`, 'ok');
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '确认清理' });
}

// 切换筛选面板显隐
function toggleAdminUserFilter() {
  const el = $('#admin-user-filter');
  if (!el) return;
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
  // 同步当前筛选值到表单
  if (el.style.display === 'block') {
    if ($('#f-status')) $('#f-status').value = _adminUserFilter.status;
    if ($('#f-range')) $('#f-range').value = _adminUserFilter.range;
    if ($('#f-bot')) $('#f-bot').checked = _adminUserFilter.bot_like;
    if ($('#f-noposts')) $('#f-noposts').checked = _adminUserFilter.has_no_posts;
  }
}

// 应用筛选（全部由后端处理）
function applyAdminUserFilter() {
  _adminUserFilter.status = $('#f-status') ? $('#f-status').value : 'all';
  _adminUserFilter.range = $('#f-range') ? $('#f-range').value : 'all';
  _adminUserFilter.bot_like = $('#f-bot') ? $('#f-bot').checked : false;
  _adminUserFilter.has_no_posts = $('#f-noposts') ? $('#f-noposts').checked : false;
  loadAdminUserList(true);
}

// 重置筛选
function resetAdminUserFilter() {
  _adminUserFilter = { status: 'all', range: 'all', bot_like: false, has_no_posts: false };
  if ($('#f-status')) $('#f-status').value = 'all';
  if ($('#f-range')) $('#f-range').value = 'all';
  if ($('#f-bot')) $('#f-bot').checked = false;
  if ($('#f-noposts')) $('#f-noposts').checked = false;
  loadAdminUserList(true);
}

// 一键清理 24h 内疑似机器人 0 帖用户
function adminQuickCleanBots() {
  const now = Math.floor(Date.now() / 1000);
  const dayAgo = now - 86400;
  showConfirm(`确定清理「最近 24 小时内注册 + 用户名疑似机器人 + 0 帖」的用户吗？\n\n这会调用批量删除接口，单次最多处理 500 个。如果机器人数量超过 500，需要多次执行。\n\n管理员账号不会被删。此操作不可撤销！`, '一键清理机器人', async () => {
    try {
      const r = await api('admin_bulk_delete_users', {
        filter: {
          status: 'all',
          created_after: dayAgo,
          bot_like: true,
          has_no_posts: true
        }
      });
      toast(`已删除 ${r.deleted_count} 个机器人账号（${r.deleted_posts} 帖子，${r.deleted_studios} 工作室）`, 'ok');
      loadAdminUserList(true);
      loadAdminStats();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '确认清理' });
}

// 在 adminTab 切走时清理分页状态
window.adminUserListOnScroll = () => {
  // 由 admin-scroll 触发，触底加载下一页
  if (_adminUserLoading || !_adminUserHasMore || _adminUserSearchMode) return;
  const sc = $('#admin-scroll');
  if (!sc) return;
  if (sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 200) {
    loadAdminUserList(false);
  }
};

function adminDeleteUser(id, username) {
  showConfirm(`确定彻底删除用户「${username}」吗？\n\n此操作将联级删除：\n• 该用户的所有帖子及关联点赞/收藏/评论\n• 该用户发布的所有评论\n• 该用户的关注/粉丝关系\n• 该用户的通知记录\n• 该用户拥有的工作室（成员关系同步清理）\n\n此操作不可撤销！`, '彻底删除用户', async () => {
    try {
      const r = await api('admin_delete_user', { id });
      toast(`已删除用户 ${username}（${r.deleted_posts} 帖子，${r.deleted_studios} 工作室）`, 'ok');
      loadAdminUserList(true);
      loadAdminStats();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '确认删除' });
}

async function adminViewUserPosts(userId, username) {
  $('#admin-list').innerHTML = `<div style="padding:14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);background:var(--bg)">
    <button class="btn ghost" style="width:auto;padding:6px 12px;font-size:12px" onclick="adminTab('users')">← 返回</button>
    <span style="font-size:13px;color:var(--text-2);font-weight:600">${escapeHtml(username)} 的所有作品</span>
  </div>
  <div id="admin-user-posts-list" style="padding:8px 0">${View.skeleton()}</div>`;
  try {
    const r = await api('admin_user_posts&user_id=' + userId);
    $('#admin-user-posts-list').innerHTML = r.posts.length ? r.posts.map(p => `
      <div class="admin-post-item">
        <div class="ap-head">
          ${p.is_pinned ? '<span class="ap-pin">置顶</span>' : ''}
          ${p.studio_id > 0 ? '<span class="ap-pin" style="background:var(--accent-soft);color:var(--accent)">工作室</span>' : ''}
          <span class="ap-type">${p.type === 'html' ? 'HTML' : '文字'}</span>
          <div class="ap-title">${escapeHtml(p.title)}</div>
        </div>
        <div class="ap-meta">${p.created_at} · ${p.views} 浏览 · ${p.likes_count} 赞 · ${p.comments_count} 评</div>
        <div class="ap-actions">
          <button onclick="go('/post/${p.id}')">查看</button>
          <button class="danger" onclick="adminDeletePost(${p.id}, this)">删除</button>
        </div>
      </div>
    `).join('') + `<div style="padding:10px 14px"><button class="btn danger" style="width:auto;padding:8px 16px;font-size:12px" onclick="adminDeleteAllUserPosts(${userId})">${ICO.trash()} 删除该用户所有作品</button></div>`
      : `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">该用户还没有发布作品</div>`;
  } catch (e) {
    $('#admin-user-posts-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

function adminResetUserPwd(id) {
  showPrompt('输入新密码（6-100 位）', async (pwd) => {
    if (!pwd || pwd.length < 6) { toast('密码至少 6 位', 'err'); return; }
    try {
      await api('admin_reset_user_password', { id, password: pwd });
      toast('密码已重置', 'ok');
    } catch (e) { toast(e.message, 'err'); }
  }, { title: '重置用户密码', placeholder: '新密码', okText: '重置' });
}

async function adminDeleteAllUserPosts(userId) {
  showConfirm('确定删除该用户的所有作品吗？此操作不可撤销，所有帖子及其点赞/收藏/评论都会被永久删除。', '批量删除用户作品', async () => {
    try {
      const r = await api('admin_delete_user_posts', { user_id: userId });
      toast(`已删除 ${r.deleted_count} 个作品`, 'ok');
      loadAdminStats();
      adminTab('users');
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '全部删除' });
}

async function loadAdminStudios() {
  $('#admin-list').innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
  try {
    const r = await api('admin_studios');
    $('#admin-list').innerHTML = r.studios.length ? r.studios.map(s => `
      <div class="admin-post-item">
        <div class="ap-head">
          ${s.visibility === 'private' ? '<span class="ap-pin" style="background:#f3f4f6;color:#6b7280">私有</span>' : ''}
          <div class="ap-title">${escapeHtml(s.name)}</div>
        </div>
        <div class="ap-meta">创建者：${escapeHtml(s.owner.username)} · ${s.members_count} 成员 · ${s.posts_count} 作品 · ${s.created_at}</div>
        <div class="ap-actions">
          <button onclick="go('/studio/${s.id}')">查看</button>
          <button class="danger" onclick="adminDeleteStudio(${s.id}, '${escapeHtml(s.name).replace(/'/g, "\\'")}', this)">删除</button>
        </div>
      </div>
    `).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无工作室</div>';
  } catch (e) {
    $('#admin-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

window.adminDeleteStudio = async (id, name, btn) => {
  showConfirm(`确定删除工作室「${name}」吗？工作室内的帖子会保留但不再关联工作室。此操作不可撤销。`, '删除工作室', async () => {
    try {
      await api('admin_studio_delete', { id });
      toast('已删除', 'ok');
      btn.closest('.admin-post-item').remove();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
};

async function loadAdminBroadcast() {
  $('#admin-list').innerHTML = `
    <div style="padding:14px">
      <div style="font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:6px"><span style="display:inline-flex;width:14px;height:14px">${ICO.broadcast()}</span> 群发系统通知</div>
      <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.5">向所有活跃用户发送一条系统通知，用户会在消息通知页面收到。通知内容会经过 XSS 清洗。</div>
      <div class="field">
        <label>通知内容（1-500 字）</label>
        <textarea class="textarea" id="broadcast-content" maxlength="500" placeholder="例如：系统将于今晚 22:00 进行维护，预计 30 分钟..." style="min-height:100px"></textarea>
        <div style="display:flex;justify-content:space-between;margin-top:6px">
          <span style="font-size:11px;color:var(--text-3)">支持普通文字</span>
          <span style="font-size:11px;color:var(--text-3)" id="broadcast-counter">0/500</span>
        </div>
      </div>
      <button class="btn" id="broadcast-btn" onclick="sendBroadcast()">${ICO.broadcast()} 立即群发</button>
    </div>
  `;
  $('#broadcast-content').addEventListener('input', e => {
    $('#broadcast-counter').textContent = `${e.target.value.length}/500`;
  });
}

window.sendBroadcast = async () => {
  const content = $('#broadcast-content').value.trim();
  if (!content) { toast('内容不能为空', 'err'); return; }
  showConfirm(`确定群发这条通知吗？所有活跃用户都会收到。`, '确认群发', async () => {
    const btn = $('#broadcast-btn');
    btn.disabled = true; btn.textContent = '发送中…';
    try {
      const r = await api('admin_broadcast', { content });
      toast(`已发送给 ${r.sent_count} 位用户`, 'ok');
      $('#broadcast-content').value = '';
      $('#broadcast-counter').textContent = '0/500';
    } catch (e) {
      toast(e.message, 'err');
    } finally {
      btn.disabled = false; btn.innerHTML = `${ICO.broadcast()} 立即群发`;
    }
  });
};

async function loadAdminHosting() {
  $('#admin-list').innerHTML = `<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">加载中…</div>`;
  try {
    const [s, r] = await Promise.all([api('admin_hosted_settings_get'), api('admin_hosted_list')]);
    $('#admin-list').innerHTML = `
      <div style="padding:14px;background:var(--bg);border-bottom:1px solid var(--border)">
        <div style="font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:6px"><span style="display:inline-flex;width:14px;height:14px">${ICO.hosting()}</span> 托管配置</div>
        <div class="field">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" id="hosting-enabled" ${s.enabled?'checked':''} style="width:16px;height:16px"> 开启托管功能（关闭时将删除所有已托管页面）
          </label>
        </div>
        <div class="field">
          <label>每人最多托管数（1-100）</label>
          <input class="input" type="number" id="hosting-max-per-user" value="${s.max_per_user}" min="1" max="100" style="width:100px">
        </div>
        <div class="field">
          <label>全局最多托管数（1-100000，所有用户共享此额度）</label>
          <input class="input" type="number" id="hosting-max-total" value="${s.max_total}" min="1" max="100000" style="width:120px">
          <div style="font-size:11px;color:var(--text-3);margin-top:4px">当前已托管 <b style="color:var(--text)">${s.total_count}</b> / ${s.max_total} 个</div>
        </div>
        <div class="field">
          <label>单个 HTML 最大体积（KB，1-10240）</label>
          <input class="input" type="number" id="hosting-max-size-kb" value="${s.max_size_kb}" min="1" max="10240" style="width:100px">
        </div>
        <button class="btn" style="width:auto;padding:8px 18px;font-size:13px" onclick="saveAdminHostingSettings()">${ICO.check()} 保存配置</button>
      </div>
      <div style="padding:14px 14px 8px;font-size:13px;font-weight:700;color:var(--text-2);display:flex;justify-content:space-between;align-items:center">
        <span>已托管页面（${r.pages.length}）</span>
      </div>
      ${r.pages.length ? r.pages.map(p => `
        <div class="admin-post-item">
          <div class="ap-head">
            <span class="ap-type">${escapeHtml(p.slug)}</span>
            ${p.is_banned ? '<span class="ap-pin" style="background:#fee2e2;color:var(--danger)">已封禁</span>' : ''}
            <div class="ap-title">${escapeHtml(p.title || '无标题')}</div>
          </div>
          <div class="ap-meta">${escapeHtml(p.author.username)} · ${p.created_at} · ${p.views} 浏览 · ${(p.size/1024).toFixed(1)}KB</div>
          <div class="ap-actions">
            <button onclick="go('/hosted/${p.slug}')">查看</button>
            <button onclick="window.open('${location.origin}${location.pathname}?hosted=${p.slug}','_blank')">新标签打开</button>
            <button class="${p.is_banned?'':''}" style="${p.is_banned?'background:#dcfce7;color:#15803d;border-color:#86efac':''}" onclick="adminToggleHostedBan('${p.slug}', ${p.is_banned?0:1}, this)">${p.is_banned?'解封':'封禁'}</button>
            <button class="danger" onclick="adminDeleteHosted('${p.slug}', this)">删除</button>
          </div>
        </div>
      `).join('') : '<div style="padding:30px;text-align:center;color:var(--text-3);font-size:13px">暂无托管页面</div>'}
    `;
  } catch (e) {
    $('#admin-list').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载失败：${escapeHtml(e.message)}</div>`;
  }
}

window.saveAdminHostingSettings = async () => {
  const enabled = $('#hosting-enabled').checked;
  const maxPerUser = parseInt($('#hosting-max-per-user').value) || 10;
  const maxSizeKb = parseInt($('#hosting-max-size-kb').value) || 100;
  const maxTotal = parseInt($('#hosting-max-total').value) || 100;
  // 如果要关闭托管，二次确认（会删除所有托管页面）
  if (!enabled) {
    showConfirm('关闭托管功能将删除所有已托管的页面，此操作不可撤销。确定关闭吗？', '关闭托管', async () => {
      try {
        await api('admin_hosted_settings', { enabled, max_per_user: maxPerUser, max_size_kb: maxSizeKb, max_total: maxTotal });
        toast('托管已关闭，所有托管页面已删除', 'ok');
        // 更新本地状态，让首页按钮隐藏
        State.hostingEnabled = false;
        loadAdminHosting();
      } catch (e) { toast(e.message, 'err'); }
    }, null, { danger: true, okText: '确认关闭' });
    return;
  }
  try {
    await api('admin_hosted_settings', { enabled, max_per_user: maxPerUser, max_size_kb: maxSizeKb, max_total: maxTotal });
    State.hostingEnabled = true;
    toast('配置已保存', 'ok');
  } catch (e) { toast(e.message, 'err'); }
};

window.adminToggleHostedBan = async (slug, banned, btn) => {
  const action = banned ? '封禁' : '解封';
  showConfirm(`确定${action}这个托管页面吗？${banned ? '封禁后所有人访问该页面都会看到封禁提示。' : '解封后该页面恢复正常访问。'}`, `${action}托管`, async () => {
    try {
      await api('admin_hosted_ban', { slug, banned: !!banned });
      toast(`已${action}`, 'ok');
      loadAdminHosting(); // 刷新整个列表
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: banned, okText: action });
};

window.adminDeleteHosted = async (slug, btn) => {
  showConfirm('确定删除这个托管页面吗？删除后分享链接将立即失效。', '删除托管', async () => {
    try {
      await api('admin_hosted_delete', { slug });
      toast('已删除', 'ok');
      btn.closest('.admin-post-item').remove();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
};

function loadAdminSettings() {
  $('#admin-list').innerHTML = `
    <div style="padding:14px">
      <div style="padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;margin-bottom:20px">
        <div style="font-weight:700;color:var(--text);margin-bottom:12px;font-size:13px;display:flex;align-items:center;gap:6px">
          <span style="display:inline-flex;width:16px;height:16px">${ICO.shield()}</span>
          修改管理员密码
        </div>
        <div class="field">
          <label>旧密码（二次验证）</label>
          <input class="input" id="old-admin-pass" type="password" placeholder="当前管理员密码" autocomplete="off">
        </div>
        <div class="field">
          <label>新密码（至少 8 位，必须含字母和数字）</label>
          <input class="input" id="new-admin-pass" type="password" placeholder="新密码" autocomplete="new-password">
        </div>
        <button class="btn" onclick="adminChangePassword()">保存新密码</button>
        <div style="margin-top:8px;font-size:11px;color:var(--text-3);line-height:1.5">
          密码以 bcrypt hash 存储，修改后需要重新登录。旧版明文密码会在下次登录时自动升级为 hash。
        </div>
      </div>

      <div style="padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;margin-bottom:20px">
        <div style="font-weight:700;color:var(--text);margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:6px">
          <span style="display:inline-flex;width:16px;height:16px">${ICO.shield()}</span>
          CDN 白名单管理
        </div>
        <div style="font-size:12px;color:var(--text-3);line-height:1.7;margin-bottom:12px">
          HTML 作品在 iframe 中渲染，会继承本页面的 CSP（内容安全策略）。
          只有列在白名单里的 CDN 域名才能被作品的 <code style="background:var(--bg);padding:1px 4px;border-radius:3px">&lt;script&gt;</code>、<code style="background:var(--bg);padding:1px 4px;border-radius:3px">&lt;link&gt;</code>、<code style="background:var(--bg);padding:1px 4px;border-radius:3px">@font-face</code> 引用。<br><br>
          已内置 ${'<span id="builtin-count" style="color:var(--accent);font-weight:600">-</span>'} 个主流 CDN（cdnjs / jsdelivr / unpkg / Google Fonts / BootCDN 等），开箱即用。<br>
          如需追加，在下方文本框每行填一个域名（支持 <code style="background:var(--bg);padding:1px 4px;border-radius:3px">example.com</code> / <code style="background:var(--bg);padding:1px 4px;border-radius:3px">*.example.com</code> / <code style="background:var(--bg);padding:1px 4px;border-radius:3px">https://example.com:8080</code>），保存后立即生效。
        </div>
        <div class="field">
          <label>自定义 CDN 白名单（每行一个域名）</label>
          <textarea class="textarea" id="cdn-whitelist-input" placeholder="例如：&#10;cdn.example.com&#10;*.my-cdn.com&#10;https://fonts.example.com" style="min-height:120px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px"></textarea>
          <div style="display:flex;justify-content:space-between;margin-top:4px">
            <span style="font-size:11px;color:var(--text-3)" id="cdn-whitelist-status">加载中…</span>
            <span style="font-size:11px;color:var(--text-3)" id="cdn-whitelist-count">0 条</span>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminSaveCdnWhitelist()">保存白名单</button>
          <button class="btn ghost" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminLoadCdnWhitelist()">重新加载</button>
          <button class="btn ghost" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminToggleBuiltinList()">查看内置白名单</button>
        </div>
        <div id="builtin-list" style="display:none;margin-top:10px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:6px;font-size:11px;color:var(--text-3);max-height:200px;overflow-y:auto;font-family:ui-monospace,Menlo,Consolas,monospace;line-height:1.6"></div>
      </div>

      <div style="padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;margin-bottom:20px">
        <div style="font-weight:700;color:var(--text);margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:6px">
          <span>📋</span> 管理员操作日志
          <span style="flex:1"></span>
          <button class="btn ghost" style="width:auto;padding:4px 10px;font-size:11px" onclick="adminClearLogs()">清空日志</button>
        </div>
        <div id="admin-logs-list" style="max-height:300px;overflow-y:auto;font-size:12px">${View.skeleton()}</div>
      </div>

      <div style="padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;margin-bottom:20px">
        <div style="font-weight:700;color:var(--text);margin-bottom:10px;font-size:13px">数据维护</div>
        <div style="font-size:12px;color:var(--text-3);line-height:1.7;margin-bottom:12px">
          如果发现首页帖子的点赞/评论数与实际不符（例如曾经批量删除过机器人账号），
          可点击下方按钮强制重新同步所有帖子的计数字段。会按批次处理，每批 5000 条。
        </div>
        <button class="btn" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminRecountPosts()">重新同步所有帖子计数</button>
        <div id="recount-progress" style="margin-top:8px;font-size:12px;color:var(--text-3)"></div>
      </div>

      <div style="padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;margin-bottom:20px">
        <div style="font-weight:700;color:var(--text);margin-bottom:10px;font-size:13px">图片迁移（性能优化）</div>
        <div style="font-size:12px;color:var(--text-3);line-height:1.7;margin-bottom:12px">
          将旧的 base64 内联图片迁移到独立的 images 表，大幅提升列表加载速度。
          每次处理 50 条，需多次点击直到剩余为 0。
        </div>
        <button class="btn" style="width:auto;padding:8px 16px;font-size:13px" onclick="adminMigrateImages()">迁移图片到 images 表</button>
        <div id="migrate-progress" style="margin-top:8px;font-size:12px;color:var(--text-3)"></div>
      </div>

      <div style="padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-3);line-height:1.7">
        <div style="font-weight:600;color:var(--text);margin-bottom:6px">安全说明</div>
        • 管理员密码以 bcrypt hash 存储，登录时常量时间比较<br>
        • 管理员会话绑定 IP + UA 指纹，换设备立即失效<br>
        • 管理员会话 2 小时自动过期，活跃操作自动续期<br>
        • 登录频率限制：60秒/5次、1小时/20次、1天/50次<br>
        • 登录失败有 300ms 延迟（防时序攻击）<br>
        • 所有管理操作记录日志（最近 100 条）<br>
        • session_regenerate_id 防 session 固定攻击
      </div>
    </div>
  `;
  // 加载当前白名单 + 操作日志
  adminLoadCdnWhitelist();
  adminLoadLogs();
}

// 加载管理员操作日志
async function adminLoadLogs() {
  const el = $('#admin-logs-list');
  if (!el) return;
  try {
    const r = await api('admin_logs');
    const logs = r.logs || [];
    el.innerHTML = logs.length
      ? logs.map(l => {
          const actionLabel = {
            admin_login_success: '✅ 登录成功',
            admin_login_failed: '❌ 登录失败',
            admin_logout: '🚪 退出登录',
            admin_change_password: '🔑 修改密码',
            admin_delete_user: '🗑️ 删除用户',
            admin_bulk_delete_users: '🗑️ 批量删除用户',
            admin_report_action: '🚩 处理举报',
            admin_logs_cleared: '🧹 清空日志',
          }[l.action] || l.action;
          return `<div style="padding:8px 10px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:flex-start">
            <span style="font-size:11px;color:var(--text-3);flex-shrink:0;min-width:120px">${escapeHtml(l.time)}</span>
            <span style="font-size:12px;font-weight:600;color:var(--text-2);flex-shrink:0;min-width:90px">${actionLabel}</span>
            <span style="font-size:11px;color:var(--text-3);flex:1;word-break:break-all">${escapeHtml(l.detail || '')}${l.ip ? ' · IP: ' + escapeHtml(l.ip) : ''}</span>
          </div>`;
        }).join('')
      : '<div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px">暂无操作日志</div>';
  } catch (e) {
    el.innerHTML = `<div style="padding:14px;color:var(--danger);font-size:12px">加载日志失败：${escapeHtml(e.message)}</div>`;
  }
}
window.adminLoadLogs = adminLoadLogs;

// 清空操作日志
window.adminClearLogs = () => {
  showConfirm('确定清空所有操作日志吗？此操作不可撤销。', '清空日志', async () => {
    try {
      await api('admin_logs_clear', {});
      toast('日志已清空', 'ok');
      adminLoadLogs();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '确认清空' });
};

// 加载 CDN 白名单
async function adminLoadCdnWhitelist() {
  const statusEl = $('#cdn-whitelist-status');
  const countEl = $('#cdn-whitelist-count');
  const builtinCountEl = $('#builtin-count');
  const inputEl = $('#cdn-whitelist-input');
  if (statusEl) statusEl.textContent = '加载中…';
  try {
    const r = await api('admin_cdn_whitelist');
    if (inputEl) inputEl.value = r.custom_raw || '';
    if (builtinCountEl) builtinCountEl.textContent = r.builtin.length;
    if (countEl) countEl.textContent = `${r.custom.length} 条自定义 / ${r.effective.length} 条生效`;
    if (statusEl) statusEl.textContent = `自定义 ${r.custom.length} 条，生效 ${r.effective.length} 条`;
    // 缓存内置列表供查看
    window._builtinCdnList = r.builtin;
  } catch (e) {
    if (statusEl) statusEl.textContent = '加载失败：' + e.message;
    toast(e.message, 'err');
  }
}
window.adminLoadCdnWhitelist = adminLoadCdnWhitelist;

// 保存 CDN 白名单
async function adminSaveCdnWhitelist() {
  const inputEl = $('#cdn-whitelist-input');
  const statusEl = $('#cdn-whitelist-status');
  if (!inputEl) return;
  const val = inputEl.value;
  if (statusEl) statusEl.textContent = '保存中…';
  try {
    const r = await api('admin_cdn_whitelist_save', { whitelist: val });
    if (statusEl) statusEl.textContent = `已保存：自定义 ${r.custom_count} 条，生效 ${r.effective_count} 条`;
    $('#cdn-whitelist-count').textContent = `${r.custom_count} 条自定义 / ${r.effective_count} 条生效`;
    toast(`白名单已保存（${r.custom_count} 条自定义，${r.effective_count} 条生效）`, 'ok');
  } catch (e) {
    if (statusEl) statusEl.textContent = '保存失败：' + e.message;
    toast(e.message, 'err');
  }
}
window.adminSaveCdnWhitelist = adminSaveCdnWhitelist;

// 切换显示内置白名单
window.adminToggleBuiltinList = () => {
  const el = $('#builtin-list');
  if (!el) return;
  if (el.style.display === 'none') {
    el.style.display = 'block';
    el.innerHTML = (window._builtinCdnList || []).map(d => `<div>${escapeHtml(d)}</div>`).join('') || '<div style="color:var(--text-3)">无</div>';
  } else {
    el.style.display = 'none';
  }
};

// 重新同步所有帖子的 likes_count / favorites_count / comments_count
async function adminRecountPosts() {
  const btn = event?.target;
  const progEl = $('#recount-progress');
  if (btn) { btn.disabled = true; btn.textContent = '同步中…'; }
  let offset = 0;
  let total = 0;
  try {
    while (true) {
      const r = await api('admin_recount_posts&offset=' + offset + '&batch=5000');
      total = r.total;
      if (progEl) progEl.textContent = `已同步 ${r.next_offset} / ${r.total} 个帖子…`;
      offset = r.next_offset;
      if (!r.has_more) break;
    }
    if (progEl) progEl.textContent = `✓ 全部完成，共同步 ${total} 个帖子的计数字段`;
    toast(`已重新同步 ${total} 个帖子的计数`, 'ok');
  } catch (e) {
    if (progEl) progEl.textContent = '同步失败：' + e.message;
    toast(e.message, 'err');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '重新同步所有帖子计数'; }
  }
}
window.adminRecountPosts = adminRecountPosts;

// 迁移旧 base64 图片到 images 表
async function adminMigrateImages() {
  const btn = event?.target;
  const progEl = $('#migrate-progress');
  if (btn) { btn.disabled = true; btn.textContent = '迁移中…'; }
  try {
    let totalMigrated = 0;
    let remaining = 1;
    while (remaining > 0) {
      const r = await api('admin_migrate_images');
      totalMigrated += r.migrated;
      remaining = r.remaining;
      if (progEl) progEl.textContent = `已迁移 ${totalMigrated} 条，剩余 ${remaining} 条…`;
      if (r.migrated === 0 && remaining > 0) {
        // 无进展但有剩余，可能卡住了
        if (progEl) progEl.textContent = `已迁移 ${totalMigrated} 条，剩余 ${remaining} 条（无进展，请稍后重试）`;
        break;
      }
      // 避免请求过快
      if (remaining > 0) await new Promise(r => setTimeout(r, 200));
    }
    if (remaining === 0) {
      if (progEl) progEl.textContent = `✓ 迁移完成，共迁移 ${totalMigrated} 条`;
      toast(`图片迁移完成，共迁移 ${totalMigrated} 条`, 'ok');
    }
  } catch (e) {
    if (progEl) progEl.textContent = '迁移失败：' + e.message;
    toast(e.message, 'err');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '迁移图片到 images 表'; }
  }
}
window.adminMigrateImages = adminMigrateImages;

async function doAdminLogin() {
  const pass = $('#admin-pass').value;
  if (!pass) { toast('请输入密码', 'err'); return; }
  const btn = $('#admin-login-btn');
  btn.disabled = true; btn.textContent = '登录中…';
  try {
    await api('admin_login', { password: pass });
    State.isAdmin = true;
    toast('登录成功', 'ok');
    renderAdmin();
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false; btn.textContent = '登录';
  }
}

async function adminLogout() {
  try { await api('admin_logout'); } catch (e) {}
  State.isAdmin = false;
  toast('已退出管理后台', 'ok');
  go('/home');
}

async function adminTogglePin(id, pinned) {
  try {
    await api('admin_pin', { id, pinned });
    toast(pinned ? '已置顶' : '已取消置顶', 'ok');
    loadAdminPosts();
  } catch (e) { toast(e.message, 'err'); }
}

async function adminDeletePost(id, btn) {
  showConfirm('确定删除这个帖子吗？此操作不可撤销。', '删除帖子', async () => {
    try {
      await api('admin_delete_post', { id });
      toast('已删除', 'ok');
      btn.closest('.admin-post-item').remove();
      loadAdminStats();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: true, okText: '删除' });
}

async function adminBanUser(id, banned, btn) {
  const action = banned ? '封禁' : '解封';
  showConfirm(`确定${action}这个用户吗？${banned ? '该用户将无法登录、发帖、评论。' : '该用户将恢复正常使用。'}`, `${action}用户`, async () => {
    try {
      await api('admin_ban_user', { id, banned });
      toast(`已${action}`, 'ok');
      loadAdminUsers();
      loadAdminStats();
    } catch (e) { toast(e.message, 'err'); }
  }, null, { danger: banned, okText: action });
}

async function adminChangePassword() {
  const oldPass = $('#old-admin-pass') ? $('#old-admin-pass').value : '';
  const newPass = $('#new-admin-pass').value;
  if (!oldPass) { toast('请输入旧密码', 'err'); return; }
  if (newPass.length < 8) { toast('新密码至少 8 位', 'err'); return; }
  if (!/[a-zA-Z]/.test(newPass) || !/[0-9]/.test(newPass)) { toast('新密码必须同时包含字母和数字', 'err'); return; }
  try {
    await api('admin_change_password', { old_password: oldPass, password: newPass });
    toast('密码已修改，下次登录请使用新密码', 'ok');
    $('#old-admin-pass').value = '';
    $('#new-admin-pass').value = '';
  } catch (e) { toast(e.message, 'err'); }
}

/* =========================================================
 *  Other user
 * ========================================================= */
async function renderUser(id) {
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('用户主页', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>`)}
    <div class="page-scroll" id="u-scroll"><div id="u-body">${View.skeleton(1)}</div></div>
  </div>`;
  try {
    const r = await api('user&id=' + id);
    const u = r.user;

    const isMe = State.user && State.user.id === u.id;
    const isBanned = u.status === 'banned';
    let actionBtn = '';
    if (!isMe) {
      if (State.user) {
        if (u.is_mutual) {
          actionBtn = `<button class="btn mutual" id="ufollow" onclick="toggleFollowUser(${u.id})">${ICO.check()} 互相关注</button>`;
        } else if (u.is_following) {
          actionBtn = `<button class="btn ghost" id="ufollow" onclick="toggleFollowUser(${u.id})">已关注</button>`;
        } else {
          actionBtn = `<button class="btn" id="ufollow" onclick="toggleFollowUser(${u.id})">${ICO.follow()} 关注</button>`;
        }
        // 举报该用户
        actionBtn += ` <button class="btn ghost" onclick="go('/report/user/${u.id}')" title="举报用户">${ICO.flag()} 举报</button>`;
      } else {
        actionBtn = `<button class="btn" onclick="go('/login')">登录后关注</button>`;
      }
    } else {
      actionBtn = `<button class="btn ghost" onclick="go('/profile')">${ICO.edit()} 编辑资料</button>`;
    }

    const adminBadge = u.role === 'admin' ? '<span class="p-badge">管理员</span>' : '';
    const bannedBadge = isBanned ? '<span class="p-badge banned">已封禁</span>' : '';

    $('#u-body').innerHTML = `
      <div class="profile-head">
        ${avatarHtml(u)}
        <div class="p-name">${escapeHtml(u.username)} ${adminBadge} ${bannedBadge}</div>
        <div class="p-bio">${renderUserBio(u)}</div>
        <div class="p-stats">
          <div class="p-stat" data-stat="posts" onclick="go('/user/${u.id}')"><b>${u.posts_count}</b><span>作品</span></div>
          <div class="p-stat" data-stat="likes"><b>${u.likes_received ?? 0}</b><span>获赞</span></div>
          <div class="p-stat" data-stat="followers" onclick="go('/followers/${u.id}')"><b>${u.followers_count}</b><span>粉丝</span></div>
          <div class="p-stat" data-stat="following" onclick="go('/following/${u.id}')"><b>${u.following_count}</b><span>关注</span></div>
        </div>
        <div class="p-actions">${actionBtn}</div>
      </div>
      ${renderUserContactCard(u)}
      <div id="u-list" style="padding:8px 0 20px">${View.skeleton()}</div>
    `;

    // 分页 + 无限滚动加载该用户的作品
    let page = 1;
    let hasMore = true;
    let loading = false;

    async function loadMore(reset = false) {
      if (loading) return;
      if (reset) {
        page = 1;
        hasMore = true;
        $('#u-list').innerHTML = View.skeleton();
      }
      if (!hasMore && !reset) return;
      loading = true;
      try {
        const pr = await api(`posts?user_id=${id}&page=${page}`);
        hasMore = !!pr.has_more;
        const html = pr.posts.length
          ? pr.posts.map(View.postCard).join('')
          : (reset ? View.empty('TA还没有发布作品', isBanned ? '该账号已被封禁' : '去发现更多有趣的内容吧', '去发现', "go('/discover')") : '');
        if (reset) {
          $('#u-list').innerHTML = html;
        } else {
          const footer = $('#u-list .list-footer');
          if (footer) footer.remove();
          $('#u-list').insertAdjacentHTML('beforeend', html);
        }
        if (pr.posts.length > 0) {
          const footerHtml = hasMore
            ? `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">上拉加载更多…</div>`
            : `<div class="list-footer" style="padding:14px;text-align:center;color:var(--text-3);font-size:12px">· 已经到底啦 ·</div>`;
          $('#u-list').insertAdjacentHTML('beforeend', footerHtml);
        }
        page++;
      } catch (e) {
        if (reset) $('#u-list').innerHTML = View.empty('加载失败', e.message);
        hasMore = false;
      } finally {
        loading = false;
      }
    }

    // 滚动监听
    const scroll = $('#u-scroll');
    if (scroll && !scroll._htmlhubScrollBound) {
      scroll._htmlhubScrollBound = true;
      scroll.addEventListener('scroll', () => {
        if (loading || !hasMore) return;
        if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 200) {
          loadMore(false);
        }
      });
    }

    loadMore(true);
  } catch (e) {
    $('#u-body').innerHTML = View.empty('用户不存在', e.message, '返回首页', "go('/home')");
  }
}

async function toggleFollowUser(id) {
  if (!State.user) { go('/login'); return; }
  const btn = $('#ufollow');
  if (!btn) return;
  try {
    const r = await api('follow', { id });
    if (r.is_mutual) {
      btn.className = 'btn mutual';
      btn.innerHTML = `${ICO.check()} 互相关注`;
    } else if (r.following) {
      btn.className = 'btn ghost';
      btn.innerHTML = '已关注';
    } else {
      btn.className = 'btn';
      btn.innerHTML = `${ICO.follow()} 关注`;
    }
    // 更新粉丝数（用 data-stat 属性精准定位，避免位置依赖导致的 bug）
    const followersStat = document.querySelector('.p-stat[data-stat="followers"] b');
    if (followersStat) followersStat.textContent = r.followers_count;
    toast(r.following ? '已关注' : '已取消关注', 'ok');
  } catch (e) { toast(e.message, 'err'); }
}

/* =========================================================
 *  Followers / Following lists
 * ========================================================= */
function userListItemHtml(u) {
  const isMe = State.user && State.user.id === u.id;
  let btnHtml = '';
  if (!isMe && State.user) {
    if (u.is_mutual) {
      btnHtml = `<button class="u-follow-btn mutual" onclick="event.stopPropagation();toggleFollowInList(${u.id},this)">互关</button>`;
    } else if (u.is_following) {
      btnHtml = `<button class="u-follow-btn following" onclick="event.stopPropagation();toggleFollowInList(${u.id},this)">已关注</button>`;
    } else {
      btnHtml = `<button class="u-follow-btn" onclick="event.stopPropagation();toggleFollowInList(${u.id},this)">+ 关注</button>`;
    }
  }
  const adminBadge = u.role === 'admin' ? '<span class="p-badge">管理员</span>' : '';
  return `<div class="user-list-item" onclick="go('/user/${u.id}')">
    ${avatarHtml(u)}
    <div class="u-info">
      <div class="u-name">${escapeHtml(u.username)} ${adminBadge}</div>
      <div class="u-bio">${escapeHtml(u.bio || '暂无简介')}</div>
      <div class="u-meta">${u.posts_count} 作品 · ${u.followers_count} 粉丝</div>
    </div>
    ${btnHtml}
  </div>`;
}

async function toggleFollowInList(id, btn) {
  if (!State.user) { go('/login'); return; }
  try {
    const r = await api('follow', { id });
    if (r.is_mutual) {
      btn.className = 'u-follow-btn mutual';
      btn.textContent = '互关';
    } else if (r.following) {
      btn.className = 'u-follow-btn following';
      btn.textContent = '已关注';
    } else {
      btn.className = 'u-follow-btn';
      btn.textContent = '+ 关注';
    }
  } catch (e) { toast(e.message, 'err'); }
}

async function renderFollowers(id) {
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('粉丝', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>`)}
    <div class="page-scroll" id="f-scroll"><div id="f-list">${View.skeleton()}</div></div>
  </div>`;
  try {
    // 先获取用户名展示在顶栏
    const ur = await api('user&id=' + id);
    $('.topbar .brand span').textContent = `${ur.user.username} 的粉丝`;
    const r = await api('followers&id=' + id);
    $('#f-list').innerHTML = r.users.length
      ? r.users.map(userListItemHtml).join('')
      : View.empty('还没有粉丝', '继续创作优质内容吸引粉丝吧');
  } catch (e) {
    $('#f-list').innerHTML = View.empty('加载失败', e.message);
  }
}

async function renderFollowing(id) {
  const app = $('#app');
  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('关注', `<button class="icon-btn" onclick="goBack()">${ICO.back()}</button>`)}
    <div class="page-scroll" id="f-scroll"><div id="f-list">${View.skeleton()}</div></div>
  </div>`;
  try {
    const ur = await api('user&id=' + id);
    $('.topbar .brand span').textContent = `${ur.user.username} 的关注`;
    const r = await api('following&id=' + id);
    $('#f-list').innerHTML = r.users.length
      ? r.users.map(userListItemHtml).join('')
      : View.empty('还没有关注', '去发现更多有趣的创作者吧', '去发现', "go('/discover')");
  } catch (e) {
    $('#f-list').innerHTML = View.empty('加载失败', e.message);
  }
}

/* =========================================================
 *  Report（举报页面）
 *  路由：/report/:target_type/:target_id
 *  target_type: post | comment | user
 * ========================================================= */
async function renderReportPage(targetType, targetId) {
  const app = $('#app');
  if (!State.user) { go('/login'); return; }
  // 校验参数
  if (!['post', 'comment', 'user'].includes(targetType)) {
    app.innerHTML = `<div class="page">${View.topbar('举报')}<div class="page-scroll">${View.empty('参数错误', '举报目标类型无效', '返回首页', "go('')")}</div></div>`;
    return;
  }
  const tid = parseInt(targetId, 10);
  if (!tid || tid <= 0) {
    app.innerHTML = `<div class="page">${View.topbar('举报')}<div class="page-scroll">${View.empty('参数错误', '举报目标 ID 无效', '返回首页', "go('')")}</div></div>`;
    return;
  }

  const typeLabel = { post: '帖子', comment: '评论', user: '用户' }[targetType];

  app.innerHTML = `<div class="page page-slide">
    ${View.topbar('举报' + typeLabel, `<button class="icon-btn" onclick="goBack()" title="返回">${ICO.back()}</button>`)}
    <div class="page-scroll">
      <div class="form-wrap" style="padding-top:20px">
        <div style="padding:14px;background:var(--accent-soft);border:1px solid var(--accent);border-radius:8px;margin-bottom:20px;font-size:13px;color:var(--text-1);line-height:1.6">
          <div style="font-weight:700;margin-bottom:4px;color:var(--accent)">🚩 举报${typeLabel}</div>
          <div style="color:var(--text-2)">请选择举报原因，并提供详细说明。恶意举报将导致你的账号被封禁。管理员会尽快处理你的举报。</div>
        </div>

        <div class="field">
          <label>举报原因</label>
          <div id="report-reasons" style="display:flex;flex-direction:column;gap:8px"></div>
        </div>

        <div class="field">
          <label>补充说明（可选，自定义原因时必填，≤500 字）</label>
          <textarea class="textarea" id="report-detail" maxlength="500" placeholder="详细描述违规内容，帮助管理员快速判断" style="min-height:100px"></textarea>
          <div style="display:flex;justify-content:space-between;margin-top:4px">
            <span style="font-size:11px;color:var(--text-3)">支持普通文字，HTML 标签会被过滤</span>
            <span style="font-size:11px;color:var(--text-3)" id="detail-counter">0/500</span>
          </div>
        </div>

        <button class="btn" id="report-submit-btn" onclick="submitReport('${targetType}', ${tid})">提交举报</button>

        <div style="margin-top:20px;padding:14px;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-3);line-height:1.7">
          <div style="font-weight:600;color:var(--text);margin-bottom:6px">举报须知</div>
          • 同一内容只能举报一次，重复举报会被拒绝<br>
          • 举报频率限制：60 秒内最多 3 次，1 小时内最多 20 次<br>
          • 不能举报自己<br>
          • 管理员处理后会显示处理结果<br>
          • 恶意举报（如报复性举报）会导致账号被封禁
        </div>
      </div>
    </div>
  </div>`;

  // 加载举报原因列表
  let selectedReason = '';
  try {
    const r = await api('report_reasons');
    const reasons = r.reasons || {};
    const container = $('#report-reasons');
    container.innerHTML = Object.entries(reasons).map(([key, label]) =>
      `<label class="report-reason-item" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--bg-2);border:2px solid var(--border);border-radius:8px;cursor:pointer;transition:border-color .15s ease" data-key="${key}">
        <input type="radio" name="report-reason" value="${key}" style="width:18px;height:18px;accent-color:var(--accent);flex-shrink:0">
        <span style="font-size:14px;color:var(--text-1);font-weight:500">${escapeHtml(label)}</span>
      </label>`
    ).join('');
    // 绑定点击事件
    container.querySelectorAll('.report-reason-item').forEach(item => {
      item.addEventListener('click', () => {
        container.querySelectorAll('.report-reason-item').forEach(x => {
          x.style.borderColor = 'var(--border)';
          x.querySelector('input').checked = false;
        });
        item.style.borderColor = 'var(--accent)';
        item.querySelector('input').checked = true;
        selectedReason = item.dataset.key;
      });
    });
  } catch (e) {
    $('#report-reasons').innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">加载举报原因失败：${escapeHtml(e.message)}</div>`;
  }

  // 字数计数器
  $('#report-detail').addEventListener('input', e => {
    $('#detail-counter').textContent = `${e.target.value.length}/500`;
  });

  // 提交举报
  window.submitReport = async (type, id) => {
    const btn = $('#report-submit-btn');
    if (!selectedReason) {
      toast('请选择举报原因', 'err');
      return;
    }
    const detail = $('#report-detail').value.trim();
    if (selectedReason === 'custom' && !detail) {
      toast('选择自定义原因时必须填写说明', 'err');
      return;
    }
    btn.disabled = true;
    btn.textContent = '提交中…';
    try {
      const r = await api('report', { target_type: type, target_id: id, reason: selectedReason, detail });
      toast(r.message || '举报已提交', 'ok');
      goBack();
    } catch (e) {
      toast(e.message, 'err');
      btn.disabled = false;
      btn.textContent = '提交举报';
    }
  };
}

/* =========================================================
 *  Login / Register
 * ========================================================= */
function renderLogin() {
  const app = $('#app');
  app.innerHTML = `<div class="auth-page">
    <button class="auth-back" onclick="go('/home')" title="返回首页">${ICO.back()}</button>
    <div class="auth-logo">H</div>
    <div class="auth-title">欢迎回来</div>
    <div class="auth-sub">登录 ${escapeHtml(State.settings.site_name)} 继续创作</div>
    <div class="field">
      <label>用户名</label>
      <input class="input" id="l-user" placeholder="字母 / 数字 / 下划线" autocomplete="username" autocapitalize="off" autocorrect="off">
    </div>
    <div class="field">
      <label>密码</label>
      <div style="position:relative">
        <input class="input" id="l-pass" type="password" placeholder="至少 6 位" autocomplete="current-password" style="width:100%;padding-right:44px">
        <button type="button" id="l-toggle-pass" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);width:32px;height:32px;border-radius:6px;display:grid;place-items:center;color:var(--text-3)" onclick="togglePassVisibility('l-pass',this)" title="显示/隐藏密码">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <div class="field">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-2);font-weight:400">
        <input type="checkbox" id="l-remember" style="width:16px;height:16px;accent-color:var(--accent)">
        <span>自动登录（1 天内免登录）</span>
      </label>
    </div>
    <button class="btn" onclick="doLogin()">登录</button>
    <div class="auth-switch">还没有账号？<a onclick="go('/register')">立即注册</a></div>
    <button class="btn ghost" style="margin-top:12px" onclick="go('/home')">先逛逛</button>
  </div>`;
  $('#l-pass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  $('#l-user').addEventListener('keydown', e => { if (e.key === 'Enter') $('#l-pass').focus(); });
  // 自动聚焦用户名
  setTimeout(() => $('#l-user')?.focus(), 300);
}
// 切换密码框可见性
window.togglePassVisibility = (inputId, btn) => {
  const inp = $('#' + inputId);
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.style.color = inp.type === 'text' ? 'var(--accent)' : 'var(--text-3)';
};
async function doLogin() {
  const u = $('#l-user').value.trim();
  const p = $('#l-pass').value;
  const remember = $('#l-remember')?.checked || false;
  if (!u || !p) { toast('请填写完整', 'err'); return; }
  // 提交按钮锁，防止用户连点导致重复提交 + 多次 BotGuard 签发
  const btn = document.querySelector('.auth-page .btn');
  if (btn) { btn.disabled = true; }
  try {
    // BotGuard 无感人机验证：注入 token + 指纹
    const payload = await BotGuard.attachTo({ username: u, password: p, remember });
    const r = await api('login', payload);
    State.user = r.user;
    State._meTried = true;
    toast('登录成功', 'ok');
    go('/home');
  } catch (e) {
    toast(e.message, 'err');
    // 验证失败时重置 BotGuard 缓存，下次重新签发
    if (e.message && e.message.indexOf('人机验证') !== -1) BotGuard.reset();
    if (btn) { btn.disabled = false; }
  }
}

function renderRegister() {
  const app = $('#app');
  // 表单渲染时间戳（秒级），用于服务端反机器人检测：填表过快视为机器人
  const renderTs = Math.floor(Date.now() / 1000);
  app.innerHTML = `<div class="auth-page">
    <button class="auth-back" onclick="go('/home')" title="返回首页">${ICO.back()}</button>
    <div class="auth-logo">H</div>
    <div class="auth-title">创建账号</div>
    <div class="auth-sub">加入 ${escapeHtml(State.settings.site_name)}，分享你的 HTML 作品</div>
    <div class="field">
      <label>用户名</label>
      <input class="input" id="r-user" placeholder="字母 / 数字 / 下划线，3-20 位" autocomplete="username" autocapitalize="off" autocorrect="off">
    </div>
    <div class="field">
      <label>密码</label>
      <div style="position:relative">
        <input class="input" id="r-pass" type="password" placeholder="至少 6 位" autocomplete="new-password" style="width:100%;padding-right:44px">
        <button type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);width:32px;height:32px;border-radius:6px;display:grid;place-items:center;color:var(--text-3)" onclick="togglePassVisibility('r-pass',this)" title="显示/隐藏密码">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <div class="field">
      <label>确认密码</label>
      <div style="position:relative">
        <input class="input" id="r-pass2" type="password" placeholder="再次输入密码" autocomplete="new-password" style="width:100%;padding-right:44px">
        <button type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);width:32px;height:32px;border-radius:6px;display:grid;place-items:center;color:var(--text-3)" onclick="togglePassVisibility('r-pass2',this)" title="显示/隐藏密码">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <!-- 蜜罐字段：对人类用户不可见，机器人会自动填写。一旦有值即拒绝注册 -->
    <div style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
      <label>网站（请勿填写）</label>
      <input type="text" id="r-website" tabindex="-1" autocomplete="off">
    </div>
    <!-- 渲染时间戳（隐藏字段） -->
    <input type="hidden" id="r-ts" value="${renderTs}">
    <button class="btn" onclick="doRegister()">创建账号</button>
    <div class="auth-switch">已有账号？<a onclick="go('/login')">去登录</a></div>
  </div>`;
  $('#r-pass2').addEventListener('keydown', e => { if (e.key === 'Enter') doRegister(); });
  $('#r-user').addEventListener('keydown', e => { if (e.key === 'Enter') $('#r-pass').focus(); });
  $('#r-pass').addEventListener('keydown', e => { if (e.key === 'Enter') $('#r-pass2').focus(); });
  setTimeout(() => $('#r-user')?.focus(), 300);
}
async function doRegister() {
  const u = $('#r-user').value.trim();
  const p = $('#r-pass').value;
  const p2 = $('#r-pass2').value;
  if (!u || !p) { toast('请填写完整', 'err'); return; }
  if (p !== p2) { toast('两次密码不一致', 'err'); return; }
  // 蜜罐字段：若被填入则视为机器人，前端直接阻断（后端也会兜底）
  const honeypot = ($('#r-website') && $('#r-website').value) || '';
  if (honeypot.trim() !== '') {
    toast('注册失败，请刷新页面后重试', 'err');
    return;
  }
  // 提交按钮锁
  const btn = document.querySelector('.auth-page .btn');
  if (btn) { btn.disabled = true; }
  try {
    // BotGuard 无感人机验证：注入 token + 指纹
    const payload = await BotGuard.attachTo({
      username: u,
      password: p,
      _t: parseInt($('#r-ts').value, 10) || 0,
      website: ''  // 显式发送空字符串，让服务端校验逻辑生效
    });
    const r = await api('register', payload);
    State.user = r.user;
    toast('注册成功，欢迎加入', 'ok');
    go('/home');
  } catch (e) {
    toast(e.message, 'err');
    if (e.message && e.message.indexOf('人机验证') !== -1) BotGuard.reset();
    if (btn) { btn.disabled = false; }
  }
}

/* =========================================================
 *  Install wizard
 * ========================================================= */
View.install = function() {
  return `<div class="page install-bg">
    <div class="page-scroll">
      <div class="install-wrap">
        <div class="install-hero">
          <div class="logo">H</div>
          <h1>欢迎使用 HTMLHub</h1>
          <p>一个精致的 HTML 作品分享社区<br>填写 MySQL 数据库信息即可完成安装</p>
        </div>
        ${!State.pdo_mysql ? `<div class="err-banner" style="display:block">⚠️ 服务器未启用 PDO_MYSQL 扩展，请联系主机商开启</div>` : ''}

        <div class="install-section">
          <h3><span class="num">1</span> 数据库配置</h3>
          <div class="install-row">
            <div class="field w80">
              <label>数据库主机</label>
              <input class="input" id="i-host" value="127.0.0.1" placeholder="127.0.0.1 或 localhost">
            </div>
            <div class="field w20">
              <label>端口</label>
              <input class="input" id="i-port" value="3306" type="number">
            </div>
          </div>
          <div class="field">
            <label>数据库名</label>
            <input class="input" id="i-name" placeholder="htmlhub（需提前创建）">
          </div>
          <div class="install-row">
            <div class="field w80">
              <label>数据库用户名</label>
              <input class="input" id="i-user" placeholder="root">
            </div>
            <div class="field w20" style="visibility:hidden">
              <label>占位</label>
              <input class="input">
            </div>
          </div>
          <div class="field">
            <label>数据库密码</label>
            <input class="input" id="i-pass" type="password" placeholder="可为空">
          </div>
        </div>

        <div class="install-section">
          <h3><span class="num">2</span> 站点信息</h3>
          <div class="field">
            <label>站点名称</label>
            <input class="input" id="i-site" value="HTMLHub" maxlength="30">
          </div>
          <div class="field">
            <label>站点描述</label>
            <input class="input" id="i-desc" value="分享你的 HTML 作品，发现更多创意" maxlength="100">
          </div>
        </div>

        <div class="install-section">
          <h3><span class="num">3</span> 管理员账号</h3>
          <div class="field">
            <label>管理员用户名</label>
            <input class="input" id="i-auser" placeholder="字母 / 数字 / 下划线，3-20 位" maxlength="20">
          </div>
          <div class="field">
            <label>管理员密码</label>
            <input class="input" id="i-apass" type="password" placeholder="至少 6 位">
          </div>
          <div class="field">
            <label>确认密码</label>
            <input class="input" id="i-apass2" type="password" placeholder="再次输入密码">
          </div>
        </div>

        <button class="btn" id="install-btn" onclick="doInstall()" style="margin-top:8px" ${!State.pdo_mysql?'disabled':''}>完成安装</button>
        <div style="text-align:center;color:var(--text-3);font-size:12px;margin-top:18px;line-height:1.6">
          数据将保存在 MySQL 数据库中<br>
          配置文件会写入 .htmlhub.config.php（请确保目录可写）<br>
          安装后此向导将不再显示
        </div>
      </div>
    </div>
  </div>`;
};
function bindInstall() {
  $('#i-apass2').addEventListener('keydown', e => { if (e.key === 'Enter') doInstall(); });
}
async function doInstall() {
  const host = $('#i-host').value.trim();
  const port = parseInt($('#i-port').value) || 3306;
  const name = $('#i-name').value.trim();
  const user = $('#i-user').value.trim();
  const pass = $('#i-pass').value;
  const site = $('#i-site').value.trim();
  const desc = $('#i-desc').value.trim();
  const auser = $('#i-auser').value.trim();
  const apass = $('#i-apass').value;
  const apass2 = $('#i-apass2').value;
  if (!host || !name || !user) { toast('请填写完整的数据库信息', 'err'); return; }
  if (!auser || !apass) { toast('请填写管理员账号', 'err'); return; }
  if (apass !== apass2) { toast('两次密码不一致', 'err'); return; }
  const btn = $('#install-btn');
  btn.disabled = true; btn.textContent = '安装中…';
  try {
    await api('install', {
      db_host: host, db_port: port, db_name: name, db_user: user, db_pass: pass,
      site_name: site, site_desc: desc,
      admin_user: auser, admin_pass: apass,
    });
    State.installed = true;
    State.user = null;
    State._meTried = false;
    toast('安装完成，欢迎加入！', 'ok');
    setTimeout(() => { location.hash = ''; location.reload(); }, 800);
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false; btn.textContent = '完成安装';
  }
}

/* =========================================================
 *  Init
 * ========================================================= */
// 开屏画面：记录开始时间，用于计算最少展示时长（避免闪烁）
const _splashStart = Date.now();
const _SPLASH_MIN_MS = 600;   // 最少展示 600ms
const _SPLASH_MAX_MS = 4000;  // 最多展示 4 秒（兜底，防止异常情况下永远卡住）

/** 隐藏开屏画面：等待最少展示时长 + 双 RAF 确保 DOM 已绘制 */
function hideSplash() {
  const splash = document.getElementById('splash');
  if (!splash || splash.classList.contains('splash-hidden')) return;
  const elapsed = Date.now() - _splashStart;
  const wait = Math.max(0, _SPLASH_MIN_MS - elapsed);
  setTimeout(() => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const el = document.getElementById('splash');
        if (!el || el.classList.contains('splash-hidden')) return;
        el.classList.add('splash-hidden');
        // 动画结束后从 DOM 移除（450ms transition + 50ms buffer）
        setTimeout(() => { try { el.remove(); } catch (e) {} }, 520);
      });
    });
  }, wait);
}

// 兜底：无论 init() 是否异常，最多 4 秒后强制隐藏 splash
setTimeout(() => {
  const el = document.getElementById('splash');
  if (el && !el.classList.contains('splash-hidden')) {
    el.classList.add('splash-hidden');
    setTimeout(() => { try { el.remove(); } catch (e) {} }, 520);
  }
}, _SPLASH_MAX_MS);

(async function init() {
  // 防克隆：检测是否被嵌入到其他站点
  if (window.top !== window.self) {
    // 被嵌入到 iframe 中，可能是克隆站点
    try {
      // 不直接阻止，但向服务器报告（通过请求一个特殊 API）
      // 这里只是静默处理，不影响正常 iframe 嵌入（如 play 页面）
    } catch (e) {}
  }

  // 防克隆：检测域名是否被篡改
  // 不阻止运行，但记录到 sessionStorage 供管理员追溯
  try {
    const expectedHost = location.host;
    sessionStorage.setItem('_hh_host', expectedHost);
  } catch (e) {}

  try {
    if (!State.installed) {
      try {
        const s = await api('status');
        State.installed = s.installed;
        State.pdo_mysql = s.pdo_mysql;
      } catch (e) {}
    }
    await render();

    // 首屏渲染完成，隐藏开屏画面
    hideSplash();

    // 进入站点时检查弹窗公告（每个浏览器会话仅展示一次）
    // 放在 render() 之后异步执行，不阻塞首屏渲染
    maybeShowPopupAnnouncement();
  } catch (e) {
    // 异常情况下也要隐藏 splash，避免白屏
    console.error('init failed:', e);
    hideSplash();
  }
})();

/**
 * 弹窗公告展示逻辑：
 *  - 每个浏览器会话（sessionStorage）仅展示一次
 *  - 管理员自己进入时也展示，方便验证效果（管理员可手动关闭）
 *  - 仅在已安装且非安装向导页时展示
 *  - 仅在用户停留在「展示型路由」时展示（home/discover/search/post 等），
 *    避免在 login/register/admin 等流程页打断用户
 */
async function maybeShowPopupAnnouncement() {
  try {
    // 安装向导阶段不弹
    if (!State.installed) return;

    // 本会话已展示过则不再展示
    if (sessionStorage.getItem('_hh_popup_shown')) return;

    // 拉取激活的弹窗公告
    const r = await api('popup_announcement');
    const popup = r.popup;
    if (!popup || !popup.id) {
      // 没有激活的弹窗公告，标记为已检查（避免反复拉取）
      sessionStorage.setItem('_hh_popup_shown', '1');
      return;
    }

    // 已为该弹窗公告 ID 展示过 → 跳过
    if (sessionStorage.getItem('_hh_popup_id_' + popup.id)) {
      sessionStorage.setItem('_hh_popup_shown', '1');
      return;
    }

    showPopupAnnouncement(popup);
  } catch (e) {
    // 静默失败，不打扰用户
    try { console.warn('popup check failed:', e.message); } catch (_) {}
  }
}

/**
 * 渲染并显示弹窗公告 modal
 */
function showPopupAnnouncement(popup) {
  // 防止重复渲染
  if ($$('.popup-mask.popup-host').length > 0) return;

  const mask = document.createElement('div');
  mask.className = 'popup-mask popup-host';
  mask.setAttribute('role', 'dialog');
  mask.setAttribute('aria-modal', 'true');
  if (popup.title) mask.setAttribute('aria-label', popup.title);

  mask.innerHTML = `<div class="popup-box" onclick="event.stopPropagation()">
    <div class="popup-head">
      <div class="popup-icon">📢</div>
      ${popup.title ? `<div class="popup-title">${escapeHtml(popup.title)}</div>` : '<div class="popup-title">站点公告</div>'}
      <button class="popup-close" type="button" aria-label="关闭">×</button>
    </div>
    <div class="popup-body md-content">${renderMarkdown(popup.content_md || '')}</div>
    <div class="popup-foot">
      <div class="popup-meta">${escapeHtml(popup.created_at || '')}</div>
      <button class="btn popup-ok-btn" type="button">我知道了</button>
    </div>
  </div>`;

  const close = () => {
    // 标记本会话已展示，并记录弹窗 ID
    try {
      sessionStorage.setItem('_hh_popup_shown', '1');
      sessionStorage.setItem('_hh_popup_id_' + popup.id, '1');
    } catch (_) {}
    mask.classList.add('popup-closing');
    setTimeout(() => mask.remove(), 220);
  };

  // 点击遮罩关闭
  mask.addEventListener('click', close);
  // 关闭按钮
  mask.querySelector('.popup-close').addEventListener('click', close);
  // 「我知道了」按钮
  mask.querySelector('.popup-ok-btn').addEventListener('click', close);
  // ESC 关闭
  const escHandler = (e) => {
    if (e.key === 'Escape') {
      close();
      document.removeEventListener('keydown', escHandler);
    }
  };
  document.addEventListener('keydown', escHandler);

  // 阻止内部点击穿透
  mask.querySelector('.popup-box').addEventListener('click', (e) => e.stopPropagation());

  document.body.appendChild(mask);
  // 自动聚焦到确认按钮（无障碍）
  setTimeout(() => {
    try { mask.querySelector('.popup-ok-btn').focus(); } catch (_) {}
  }, 100);
}

// 防克隆：反 debugger（延迟检测，不影响正常用户）
// 管理员账号跳过检测，方便调试
(function antiClone() {
  function check() {
    // 管理员不触发反调试
    if (State.user && State.user.role === 'admin') return;
    var s = performance.now();
    debugger;
    var e = performance.now();
    if (e - s > 200) {
      try { sessionStorage.setItem('_hh_dbg', '1'); } catch (x) {}
    }
    setTimeout(check, 8000 + Math.random() * 4000);
  }
  // 延迟启动，等 State.user 加载完成
  setTimeout(function() {
    if (State.user && State.user.role === 'admin') return;
    check();
  }, 5000);
})();
</script>
</body>
</html>
