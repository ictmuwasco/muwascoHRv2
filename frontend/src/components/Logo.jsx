import React from 'react'

const Logo = ({ className = 'h-30 w-30' }) => {
  return (
    <img
      src="/assets/muwascologo.png"
      alt="MUWASCO Logo"
      className={`${className} object-contain rounded`}
    />
  )
}

export default Logo