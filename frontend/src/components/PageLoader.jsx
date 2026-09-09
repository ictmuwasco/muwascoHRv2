import React from "react";

const PageLoader = () => {
  return (
    <div style={{
      display: "flex",
      justifyContent: "center",
      alignItems: "center",
      minHeight: "200px",
      width: "100%"
    }}>
      <div style={{
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        gap: "12px"
      }}>
        <div style={{
          width: "40px",
          height: "40px",
          border: "3px solid #f3f3f3",
          borderTop: "3px solid #3498db",
          borderRadius: "50%",
          animation: "spin 1s linear infinite"
        }} />
        <span style={{ color: "#666", fontSize: "14px" }}>Loading...</span>
      </div>
      <style>{`
        @keyframes spin {
          0% { transform: rotate(0deg); }
          100% { transform: rotate(360deg); }
        }
      `}</style>
    </div>
  );
};

export default PageLoader;
