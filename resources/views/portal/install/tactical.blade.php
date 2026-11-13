@if($installerError ?? null)
    <div class="alert alert-warning" role="alert">{{ $installerError }}</div>
@endif
@if($platform === 'windows')
    <h3 class="h5">Recommended: guided Windows setup</h3>
    <ol>
        <li>Choose your system type (Settings → System → About). If unsure, ask us. Windows ARM is not offered here.</li>
        <li>Download <strong>workstation-setup.exe</strong> below, then open that file from your browser's downloads. It acquires and installs the agent and requests administrator permission. No command shell is needed.</li>
        <li>Ask us to confirm this computer has checked in to the intended organization and site. Download completion or a successful process exit is not proof of enrollment.</li>
    </ol>
    <p class="small">Certificate and security filters: the vendor documents that this generated wrapper uses Amidaware's own certificate authority, unlike its regular installer. Signing also depends on the vendor configuration; we cannot promise the publisher name or trust prompt you will see. Windows or security software sometimes flags or blocks this file. A warning is not proof that a file is safe or unsafe. If you are concerned or want help, call {{ $package->mspName }} using the support details below before continuing.</p>
    <details class="mt-2"><summary>If security software blocks this specific file</summary>
        <p>Only continue if you requested this setup from us and have confirmed the download with us. These steps apply only to <strong>workstation-setup.exe</strong> from this request, not to other files.</p>
        <ul>
            <li>If Microsoft Defender SmartScreen says “Windows protected your PC”, choose <strong>More info</strong>, check the file and publisher details, then <strong>Run anyway</strong> only if available and we have confirmed this file. An unknown or unexpected publisher is a reason to call for help, not proof of authenticity.</li>
            <li>If Windows Security quarantines it, open <strong>Windows Security → Virus &amp; threat protection → Protection history</strong>, select the entry for this exact download, and ask us to check the detection. Only after that confirmation, use <strong>Actions → Allow on device</strong> if offered. Do not allow a different detection or a file you did not request.</li>
            <li>If a browser blocks the download, ask us to verify it before using a per-file <strong>Keep</strong> option, if offered. Other security products and managed policies differ: if the option is missing or blocked by policy, stop and call for help or use the ordered manual fallback.</li>
        </ul>
        <p>Never turn off antivirus, add folder/process exclusions, import a root certificate, or change organization security policy. Administrator permission (UAC) is separate: confirm you intended to run this installer; cancel and call if unsure.</p>
    </details>
    <p class="small">This installer contains an enrollment credential valid for up to seven days and can be reused until it expires. Keep it private, do not forward it, and delete it after use. A fresh request creates a new credential; it does not revoke older downloads.</p>
    <form method="POST" action="{{ route('portal.install.command', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="platform" value="windows">
        <input type="hidden" name="method" value="exe">
        <input type="hidden" name="nonce" value="{{ $installerNonce ?? '' }}">
        <label>Windows system type
            <select name="goarch" required class="form-select">
                <option value="amd64">64-bit Intel / AMD (x64)</option>
                <option value="386">32-bit Intel / AMD (x86)</option>
            </select>
        </label>
        <button class="btn btn-accent mt-2" type="submit">Download guided Windows setup</button>
    </form>
    <details class="mt-3"><summary>Technician note</summary>
        Antivirus sandboxes can execute the dynamic EXE and enroll a sandbox device. Verify the real computer's identity and intended-site check-in rather than assuming the first new record is this computer. Signature, publisher/UAC prompts and clean-machine enrollment require operator verification.
    </details>
    <hr>
    <h3 class="h5">Ordered manual fallback</h3>
@else
    <h3 class="h5">Install from Terminal</h3>
    <p>The command acquires the agent, installs it, and requests administrator permission. Do not download and double-click a bare agent instead.</p>
@endif
@if($info === null)
    <form method="POST" action="{{ route('portal.install.command', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="platform" value="{{ $platform }}">
        <input type="hidden" name="method" value="manual">
        <label>Architecture
            <select name="goarch" required class="form-select">
                <option value="amd64">Intel / AMD 64-bit</option>
                @if($platform === 'windows')
                    <option value="386">Intel / AMD 32-bit</option>
                @else
                    <option value="arm64">ARM 64-bit{{ $platform === 'mac' ? ' / Apple Silicon' : '' }}</option>
                @endif
            </select>
        </label>
        <button type="submit" class="btn btn-outline-secondary mt-2">{{ $platform === 'windows' ? 'Request manual fallback' : 'Show Terminal command' }}</button>
    </form>
@elseif($info->hasScript() && ($platform !== 'windows' || ($info->expectedFilename && $info->hasDownload())))
    @if($platform === 'windows')
        <ol>
            <li>Open <strong>PowerShell as Administrator</strong> (Windows PowerShell 5.1 or later). No separate download or change of folder is needed.</li>
            <li>Copy and paste the command below once. It downloads the selected architecture's installer as exactly <strong>{{ $info->expectedFilename }}</strong> into a new temporary folder, checks the download, then installs and enrolls. It stops on failure and removes the temporary installer.</li>
            <li>If setup stops or a security policy blocks it, contact us. Do not disable protection or keep rerunning the command. A completed process is not proof of enrollment; ask us to verify intended-site check-in.</li>
        </ol>
    @else
        <p>Open Terminal and run the command below. It downloads the agent itself; enter your administrator password when requested.</p>
    @endif
    <div class="script-block" id="script-{{ $platform }}">{{ $info->installScript }}</div>
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="copyScript('{{ $platform }}')">Copy command</button>
    <p class="small mt-2">Keep this command private: its enrollment credential expires after seven days. Stop if download or installation fails; do not keep rerunning it. Contact us with the error, not the command or credential. Ask us to verify this computer's identity and intended-site check-in. A download or process exit alone does not establish enrollment.</p>
@else
    <p role="alert">A complete manual installation command and filename could not be prepared. Contact us; a bare download is not an alternative.</p>
@endif
