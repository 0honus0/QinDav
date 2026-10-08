# QinDav

基于 **sabre/dav 4.7** 的单用户 WebDAV 应用，应用逻辑集中在 `index.php`，`dav.php` 是独立 WebDAV 入口。
不使用数据库。Composer 依赖、运行配置、会话、锁信息和用户文件仍然是独立文件。

## 已实现

- 首次注册管理员，创建后关闭注册；网页登录、退出、修改密码。
- 独立应用密码，生成、替换、撤销，登录后可在设置弹窗随时查看或复制。
- 文件浏览、分页、并发上传、下载、新建目录、移动、重命名、递归删除。
- 参考 h5ai 的浅色文件索引界面：文件类型图标、路径导航、上级目录、当前页筛选与排序，适配手机。
- 右上角设置弹窗管理连接地址、应用密码和登录密码，支持 Esc、背景点击及关闭后的焦点恢复。
- 设置容量上限（0 表示不限），查看已用文件容量、文件数量、上传预留、额度剩余和磁盘剩余空间。
- 网页中 32 MiB 及以上文件自动分块上传，16 MiB 每块，全局最多 4 路传输。
- 标准 WebDAV：PROPFIND、PUT、GET、HEAD、MKCOL、COPY、MOVE、DELETE、LOCK、UNLOCK。
- HTTP 条件请求、ETag、下载字节范围；rclone 使用 `vendor = other`。
- 文件锁使用 JSON 和 `flock`，整个读改写事务加锁，原子替换状态文件。
- 在设置中检查 GitHub 最新版本并更新；自动备份、可配置保留份数、按内容去重及一键回退。

## 快速运行

要求 64 位 PHP 8.2+、DOM/XML、mbstring 和 Composer。Linux 推荐。

```bash
composer install --no-dev --optimize-autoloader
WEBDAV_STATE_DIR=/tmp/my-webdav php -S 127.0.0.1:8080 tools/router.php
```

打开 `http://127.0.0.1:8080/` 创建账号。WebDAV 地址为
`http://127.0.0.1:8080/dav.php/`。开发服务器仅适合本机体验，不用于生产或性能评估。

状态目录必须在应用目录以外，PHP 用户需有读写权限。未设置时使用应用父目录下
`.webdav-state-<应用路径指纹>`。目录结构如下：

```text
config.json       管理员、密码哈希、可查看的应用密码、认证密钥
config.json.lock  配置事务锁
files/            用户文件
sessions/         网页会话
auth/             短期认证缓存，只有带密钥的指纹及过期时间
rate/             失败登录次数
uploads/          网页分块任务、分块完成记录、任务锁
usage.json        容量上限、增量用量统计、上传额度预留
usage.json.lock   用量和额度事务锁
locks.json        WebDAV 锁，仅发生 LOCK 操作后创建
locks.json.lock   WebDAV 锁事务锁
application.lock 请求与程序替换协调锁
updates/         程序更新临时文件及版本备份
update-*.json    版本检查缓存、备份保留策略及中断恢复日志
```

## 打包复制

```bash
python3 tools/build.py
```

生成 `dist/QinDav.zip`，包含 `index.php`、`dav.php` 和 `vendor/`，依赖已包含，
服务器无需运行 Composer。将包内容解压到已支持 PHP 的网站根目录，访问域名创建账号即可。
升级时覆盖同一应用目录，保留网站目录之外的状态目录。
`dist/SHA256SUMS` 提供文件完整性校验。

## 主机部署与性能

复制 `index.php`、`dav.php` 和 `vendor/` 到网站目录。管理页面地址为域名根路径，
WebDAV 唯一地址为 `https://你的域名/dav.php/`。前置服务需支持 PHP PATH_INFO，
即 `/dav.php/文件名` 由实际的 `dav.php` 执行。包内没有服务器配置或重写规则。
不需要数据库，不需要在主机上运行 Composer。要求 64 位 PHP 8.2+、DOM/XML、mbstring。

状态目录仍位于站点根目录之外；PHP 用户需具有读写权限，且该目录在 open_basedir
允许范围内。首次公开注册可通过现有环境设置 `WEBDAV_SETUP_TOKEN`。公开访问使用 HTTPS。

性能设计：

1. 上传直接使用原始 PUT 请求和流复制，不把整个文件读入 PHP 内存。
   临时文件与目标文件在同一目录，成功后原子重命名，替换旧文件时不暴露半成品。
   验证 Content-Length，失败时保留旧文件。上传替换期间需要额外磁盘空间。
   PHP/FastCGI 可能仍有内部请求体缓冲，实际磁盘写入量取决于运行环境。
   网页大文件通过管理 API 分块，直接写入同一稀疏临时文件的不同偏移。
   全部分块完成后使用硬链接一次性发布，无额外全文件拼接复制，并且不覆盖同名文件。
   存储需支持同一文件系统内的硬链接，应用在开始上传前检查支持情况。
   会话锁在传输前释放；并发分块分别加锁，完成/取消等待写入结束。
   失败的分块最多重试两次，完成响应丢失也可安全重试，目标文件锁在发布前再次检查。
   失败任务会取消清理；关闭页面留下的任务在 24 小时后、下次新建任务时清理。
   此功能用于网页管理，不改变标准 WebDAV 的 PUT 行为，也不增加其他 DAV 入口。
2. 设置 `WEBDAV_ACCEL_PREFIX=/_dav_files/` 后，鉴权及条件检查由 PHP 完成，
   下载交给 Nginx 的 internal location 和 sendfile，PHP 进程无需持续发送大文件。
   普通 Range 下载由 Nginx 处理；带 If-Range 的请求由 sabre/dav 流式处理，
   确保与应用 ETag 的条件语义一致。HEAD 直接返回 PHP 元数据，不读取文件内容。
   配置由已有前置服务提供；本项目不生成或修改服务器配置。未设置时使用 PHP 流式下载。
   **只有配置好了 Nginx internal location 才能启用此变量。**
3. 应用关闭 PHP 输出压缩，上传使用流复制。前置服务的缓冲和限制由主机管理，
   不在部署包内设置。
4. 成功的 Basic Auth 校验缓存 5 分钟，后续小文件请求不重复执行 bcrypt。
   文件仅保存 HMAC 指纹，不保存密码；改密码或撤销应用密码立即失效。
5. ETag 基于 inode、大小和时间，不为每次查询计算全文件哈希。
   同一磁盘的跨目录 MOVE 使用 rename，大目录移动不递归复制。
6. 网页会话在文件操作前关闭；WebDAV Basic Auth 不创建会话。
   网页文件上传最多 4 路并发，不用 multipart POST。已有文件不会静默覆盖。
7. 目录迭代不递归统计空间、不全量排序。网页每页 200 项，磁盘可用容量通过
   filesystem stats 查询。分页按文件系统顺序；并发修改目录时页边界可能变化。
   WebDAV Depth:1 仍须返回完整目录，超大目录的 XML 响应仍消耗与条目数成比例的内存。

每个活跃上传占用一个 PHP 工作进程；共享主机的进程配额、超时、带宽和磁盘性能
会影响吞吐。应用层无法取消主机管理员强制的限制。

## rclone

WebDAV 地址固定为 `/dav.php/`，由独立的 `dav.php` 处理。
`index.php` 只处理管理页面，没有其他 WebDAV 地址别名。

使用 `rclone config` 创建 remote，选择 `webdav`，URL 为 `https://你的域名/dav.php/`，
vendor 选择 `other`，输入管理员用户名和应用密码。

```ini
[private]
type = webdav
url = https://你的域名/dav.php/
vendor = other
user = admin
pass = <由 rclone config 保存的 obscured 密码>
```

```bash
# 小文件：先从 8 路传输、16 路检查开始
rclone copy ./local private:backup --disable-http2 --transfers 8 --checkers 16 --progress

# 大文件下载：满足 rclone 多线程阈值时使用 Range 并发读取
rclone copy private:large.bin ./downloads --disable-http2 --multi-thread-streams 4 --progress
```

示例使用 `--disable-http2`；不同主机的 HTTP/1.1 与 HTTP/2 表现不同，可分别测试后选择。
实际速度取决于主机带宽、PHP 工作进程、磁盘及客户端并发设置。

当前是标准 WebDAV，不伪装 Nextcloud。支持断点/分段**下载**，不实现 Nextcloud
分块上传或 PUT 断点续传；客户端的 PUT 上传失败需要重传。网页使用单独的分块管理 API，
不支持刷新页面后继续任务。通用 WebDAV 不提供内容校验和及
远程修改时间设置，rclone 同步可按大小比较；需要内容完整性检查时使用
`rclone check --download`，它会读取文件内容，不能当成低成本元数据检查。
不建议对频繁同大小修改的文件只使用 `--size-only` 判断是否需要同步。

## 容量与用量

右上角“设置”中的“存储空间”可用 GiB、TiB 或 MiB 设置容量上限，0 表示不限。
默认不限；升级保留现有账号和文件，首次使用该功能时自动统计已有文件。
上限不能小于已用空间与正在上传的预留空间之和。

用量按可访问的文件内容长度统计，包含各级目录内的文件，不包含文件夹元数据、
依赖、会话、隐藏上传临时文件或文件系统块开销。磁盘可用空间单独显示。
上传会预留预计新增用量；替换文件按新旧文件长度之差判断额度，完成后重新检查，
避免并发上传、删除或移动期间超额。PUT（含未知长度流）、COPY 和网页分块上传均
遵守上限；超额返回 HTTP 507，失败的文件上传保留旧内容。DELETE 释放用量，
MOVE 不增加用量。WebDAV 提供 quota-used-bytes 和 quota-available-bytes 属性。
普通文件 COPY 覆盖目标时也先暂存再提交，额度不足时保留原目标文件。

正常上传、替换和删除使用 JSON 增量统计与短时间的文件锁，不扫描整个目录，
传输过程中不会持有全局用量锁。分块取消或过期释放预留额度；未完成请求因进程
终止而留下的预留最多保留 24 小时。进程在提交与统计之间意外终止时，下次操作会
重建用量。通过 FTP、SSH 或其他程序改动用户文件后，点击“重新统计”校正记录。

## 安全与维护

- 上传文件在网站根目录之外；依赖及源码的 HTTP 访问保护由已有前置服务提供。
- 拒绝路径穿越、符号链接、特殊文件及 `.dav-upload-` 开头的保留文件名。
  应由应用独占管理 files 目录，不能让不可信本地用户同时修改目录或链接。
- 会话 Cookie 使用 HttpOnly 和 SameSite=Strict，HTTPS 下启用 Secure。
  网页修改请求及使用会话认证的 WebDAV 写操作必须提交 CSRF token。
- 修改登录密码会使其他网页登录会话失效；独立应用密码继续有效，需单独撤销。
- 同一来源 IP 在 5 分钟内失败 10 次会限制继续校验；已经缓存的合法 DAV 请求
  仍可继续，避免失败请求打断正常传输。
- 整个账号仅有一个应用密码槽位，生成新密码会替换旧密码。
- 为支持重复查看，应用密码明文保存在网站根目录外的私有 config.json（0600），
  仅登录会话携带 CSRF token 的管理接口可读取；不嵌入页面，也不使用 DAV 密码授权读取。
  旧版本仅存哈希，旧应用密码继续有效，但需要重新生成一次才能查看。
  查看后可隐藏，关闭设置会清除页面上的密码。
- 普通异常会清理临时上传；进程被强制杀死可能留下 `.dav-upload-*` 文件。
  停止上传后可清理遗留文件。备份时不要把临时上传文件当作完整数据。
- `auth/` 和 `rate/` 中的过期 JSON 不影响认证，可定期清理；不要删除活动的
  `.lock` 文件。单用户情况下增长主要来自更换密码、不同客户端和不同来源 IP。
- 配置写入通过原子 rename 防止半文件，未承诺断电后的 fsync 级持久性。
- 备份 config.json 与 files，保管配置中的应用密码明文、密码哈希及 secret；锁和会话无需迁移。

## 开发与测试

仓库只跟踪源码、依赖锁文件、测试、构建脚本和工作流，不提交 vendor、部署包、
运行数据、真实账号或线上测试记录。Node 和 Playwright 仅用于开发测试，部署不需要。

```bash
composer install --no-dev --optimize-autoloader
# 安装 rclone 后进行协议、权限、容量、分块和实际客户端验证
python3 tests/integration.py --rclone
# 更新、回退、去重、校验与中断恢复；使用临时实例及本地下载夹具
python3 tests/update.py
# 可选本地回环基准；不代表公网吞吐
python3 tests/integration.py --benchmark --rclone
# 浏览器界面、密钥查看/复制与并发上传验证
npm ci
npx playwright install --with-deps chromium
npm test
# 生成部署包并验证 PHP 语法、ZIP 内容
python3 tools/build.py
```

测试自动启动临时本地 PHP 实例，创建一次性账号并清理数据，不访问线上服务。
不要将集成测试的 `--url` 指向已有账号或用户数据的实例。

## GitHub Actions

- **Test**：main 推送及 Pull Request 自动运行 PHP 8.2、8.3、8.4 的协议与 rclone 测试；
  所有 PHP 版本运行更新/回退测试，PHP 8.4 还运行 Chromium 浏览器测试；所有版本检查部署包。
- **Release**：推送 `vX.Y.Z` 标签触发发布。也可在 Actions 中手动运行 Release，
  填写尚未使用的版本标签。发布先通过完整测试，再从 Composer 锁文件安装生产依赖，
  生成并上传 `QinDav.zip` 与 `SHA256SUMS` 到 GitHub Releases。

```bash
git tag v1.0.0
git push origin v1.0.0
```

用户部署时下载 Release 中的 `QinDav.zip`，无需安装 Composer、Node 或测试工具。
压缩包仅包含 `index.php`、`dav.php` 和 `vendor/`。

## 应用内更新与回退

首次从旧版升级至 v1.1.0，需要手动覆盖部署包。之后在右上角设置的“程序更新”中
检查最新稳定版本并点击“立即更新”。发布源固定为 `0honus0/QinDav` 的 GitHub Releases；
PHP 通过验证证书的 HTTPS 下载部署包及 `SHA256SUMS`，检查 SHA-256、包内路径和版本。
校验值来自同一受信任的 GitHub 发布源，并非独立的数字签名。

自动更新需要 PHP cURL 和 ZIP 扩展、应用目录写权限、状态与应用目录位于同一文件系统，
并能访问 GitHub API 与发布下载地址。设置会显示未满足的条件，仍可下载 ZIP 手动覆盖。
更新下载期间正常请求可继续；替换前若仍有传输或其他请求执行，更新返回“稍后重试”。
替换阶段暂停接收新请求，已进入 PHP 的上传或下载不会被替换动作打断。
前置服务接管的下载不受 PHP 锁协调，但其用户文件不会被程序更新改动。

更新与回退只替换 `index.php`、`dav.php` 和 `vendor/`。账号、应用密码、容量设置和用户文件
留在状态目录，回退不会恢复或删除用户数据。目录替换失败自动恢复旧程序；PHP 进程在
替换中断后，下次请求会先处理恢复日志，再加载依赖，恢复完成时请重试请求。

每次替换前备份当前程序，默认保留最近 **2** 份，可在设置中改为 **1–10** 份。
降低保留份数立即清理超额备份。备份按入口和依赖的路径与文件内容生成 SHA-256 指纹：
两个版本来回切换时复用相同快照，并更新其保留顺序，不重复保存。同版本号但内容不同
的程序仍视为不同快照。选中的备份与当前程序完全相同时，不执行回退；回退前再次
校验备份内容。设置中列出版本与首次备份时间，最近使用的快照优先保留，可直接点击“回退此版本”。

手动覆盖 ZIP 本身不生成应用备份。保留目录位于私有状态目录的 `updates/`，不在网站根目录。
若主机禁止程序写入、无法正常执行更新恢复，仍可手动复制备份中的 `old/` 内容恢复程序。
