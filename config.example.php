<?php
/**
 * 配置模板（可提交到仓库）
 * 部署步骤：
 *   1. 复制本文件为 index_assets/config.php
 *   2. 填入你在 https://enter.pollinations.ai/keys 创建的 App Key 等信息
 * 注意：index_assets/config.php 已被 .gitignore 排除，不会上传；请勿把真实密钥写入本模板。
 */

declare(strict_types=1);

// OAuth App Key（publishable key）—— 在 https://enter.pollinations.ai/keys 创建
const OAUTH_CLIENT_ID = 'pk_your_key_here';

// 开发者密钥：用于赠送的免费生图（由开发者支付，仅存服务器端，不下发浏览器）
const DEVELOPER_KEY = 'sk_your_developer_key_here';

// 免登录邀请码的 SHA-256 哈希（不存明文，生成方式：echo hash('sha256', '你的邀请码');）
const GUEST_TOKEN_HASH = 'your_invite_code_sha256_hash';
