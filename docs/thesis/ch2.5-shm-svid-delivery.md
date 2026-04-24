# 2.5 SHM 的 SVID 交付

於 SPIFFE/SPIRE 架構中，SVID（SPIFFE Verifiable Identity Document）之原生交付模式為透過 Workload API 以 gRPC 串流將 X.509 憑證與 JWT Bundle 直接推送至單一工作負載 [1]。然而於多行程之事件驅動伺服器（如 OpenSwoole、Prefork Worker Pool）中，若每一 Worker 各自訂閱 Workload API 串流，將導致 socket 描述符倍增、憑證輪換事件被重複處理，亦難以維持跨行程之身份快照一致。

共享記憶體（Shared Memory, SHM）為行程間通訊之典型機制，可將「身份取得」與「身份消費」解耦 [2]。本研究以單一長駐之 watcher 行程作為 Workload API 的唯一消費者，透過 POSIX 原子 rename 語意 [3] 將 X.509-SVID、JWT Bundle 與中繼資料寫入以 tmpfs 為基底之共享目錄，供 Gateway 與 Worker 以唯讀方式取用，避免讀者觀察到寫入中途之部分狀態。

為確保跨多檔案之一致性快照，本系統採用 Lamport 所提出之 seqlock 協議 [4]：writer 於寫入前將版本計數器加一（奇數，標示臨界區開始）、寫入完成後再加一（偶數）；reader 於讀取前後兩次取樣版本號，若不相等或為奇數則回退重試。相較於讀寫互斥鎖，seqlock 具備「讀者不阻塞寫者」之特性，尤其適用於憑證輪換此類寫少讀多之場景 [5]。

---

## 參考文獻

[1] SPIFFE Authors, "The SPIFFE Workload Endpoint Specification," *SPIFFE Project Standards*, 2023. [Online]. Available: https://github.com/spiffe/spiffe/blob/main/standards/SPIFFE_Workload_Endpoint.md

[2] W. R. Stevens and S. A. Rago, *Advanced Programming in the UNIX Environment*, 3rd ed. Boston, MA, USA: Addison-Wesley, 2013, ch. 15 (Interprocess Communication).

[3] The Open Group, *IEEE Std 1003.1-2017 (POSIX.1-2017) — System Interfaces: rename()*, Institute of Electrical and Electronics Engineers, 2018.

[4] H.-J. Boehm, "Can seqlocks get along with programming language memory models?," in *Proc. ACM SIGPLAN Workshop on Memory Systems Performance and Correctness (MSPC '12)*, Beijing, China, 2012, pp. 12–20.

[5] C. Lameter, "Effective synchronization on Linux/NUMA systems," *Linux Kernel Documentation — `locking/seqlock.rst`*. [Online]. Available: https://www.kernel.org/doc/Documentation/locking/seqlock.rst
